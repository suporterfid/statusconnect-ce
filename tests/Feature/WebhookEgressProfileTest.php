<?php

namespace Tests\Feature;

use App\Application\Tenancy\AccountProvisioningService;
use App\Domain\Notifications\Transports\WebhookNotificationTransport;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #194: the `webhook` egress profile had no arm in UrlValidator, so every
 * webhook to a hostname failed with UnhandledMatchError. It now behaves like
 * public-crawl: public destinations pass, private ones are refused.
 */
class WebhookEgressProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return match ($hostname) {
                    'hooks.example.com' => ['93.184.216.34'],
                    'intranet.example.com' => ['10.0.0.5'],
                    default => [],
                };
            }
        });
    }

    public function test_public_hostname_passes_the_policy(): void
    {
        $endpoint = app(\App\Domain\Outbound\OutboundPolicy::class)
            ->validateUrl('https://hooks.example.com/statusconnect', egressProfile: \App\Domain\Outbound\EgressProfile::Webhook);

        $this->assertSame('93.184.216.34', $endpoint->pinnedIp);
    }

    public function test_public_literal_ip_passes_the_policy(): void
    {
        $endpoint = app(\App\Domain\Outbound\OutboundPolicy::class)
            ->validateUrl('https://93.184.216.34/hook', egressProfile: \App\Domain\Outbound\EgressProfile::Webhook);

        $this->assertSame('93.184.216.34', $endpoint->pinnedIp);
    }

    public function test_private_destinations_are_still_refused(): void
    {
        $account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);

        foreach (['https://intranet.example.com/hook', 'http://127.0.0.1:8080/x', 'http://169.254.169.254/latest/meta-data/', 'http://10.1.2.3/x'] as $url) {
            $channel = NotificationChannel::query()->create([
                'tenant_id' => $account->tenant->id,
                'environment_id' => $account->environment->id,
                'name' => 'Hook',
                'type' => 'webhook',
                'config_json' => ['url' => $url],
                'enabled' => true,
            ]);

            $result = app(WebhookNotificationTransport::class)->send($channel, ['event' => 'test']);

            $this->assertSame('failed', $result['status'], $url);
            $this->assertSame(403, $result['response_code'], $url);
            $this->assertStringContainsString('Outbound policy violation', $result['error_excerpt'], $url);
        }
    }
}
