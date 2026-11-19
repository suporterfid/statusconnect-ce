<?php

namespace Tests\Feature;

use App\Application\ApiKeys\ApiKeyService;
use App\Application\Notifications\Services\NotificationDispatcherService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Application\Tenancy\ProvisionedAccount;
use App\Domain\Outbound\DnsResolverInterface;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\NotificationChannel;
use App\Infrastructure\Persistence\Eloquent\NotificationDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Telegram channel type and sending (#189), against a fake Bot API. */
class TelegramTransportTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAFakeTelegramTokenForTests_abcdefghij';

    private ProvisionedAccount $account;

    private FakeTelegramApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        config(['telegram.bot_token' => self::TOKEN, 'telegram.bot_username' => 'StatusConnectTestBot']);
        $this->app->bind(DnsResolverInterface::class, fn () => new class implements DnsResolverInterface {
            public function resolve(string $hostname): array
            {
                return $hostname === 'api.telegram.org' ? ['149.154.167.220'] : [];
            }
        });
        $this->api = new FakeTelegramApi;
        $this->app->instance(PinnedHttpTransport::class, $this->api);

        $this->account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
            'locale' => 'pt-BR', 'timezone' => 'America/Sao_Paulo',
        ]);
    }

    public function test_opened_and_resolved_incidents_reach_the_chat(): void
    {
        $channel = $this->channel();
        $monitor = $this->monitor('Checkout API');
        $incident = Incident::query()->create([
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitor->id,
            'started_at' => '2026-11-12T17:03:00Z',
            'confirmed_at' => '2026-11-12T17:03:00Z',
            'severity' => 'major',
            'summary' => 'HTTP 503',
        ]);
        $dispatcher = app(NotificationDispatcherService::class);

        $dispatcher->dispatchForIncident($incident, 'opened');
        $incident->forceFill(['resolved_at' => '2026-11-12T17:15:00Z', 'resolved_flag' => true, 'duration_seconds' => 720])->save();
        $dispatcher->dispatchForIncident($incident->fresh(), 'resolved');

        $this->assertCount(2, $this->api->requests);
        [$opened, $resolved] = $this->api->bodies();
        $this->assertSame('sendMessage', $this->api->method(0));
        $this->assertSame(-1001234567890, $opened['chat_id']);
        $this->assertSame('HTML', $opened['parse_mode']);
        $this->assertTrue($opened['link_preview_options']['is_disabled']);
        // pt-BR and the status page time zone: 17:03 UTC is 14:03 in São Paulo.
        $this->assertStringContainsString('<b>Fora do ar: Checkout API</b>', $opened['text']);
        $this->assertStringContainsString('12/11 14:03 (America/Sao_Paulo)', $opened['text']);
        $this->assertStringContainsString('severidade major', $opened['text']);
        $this->assertStringContainsString('HTTP 503', $opened['text']);
        $this->assertStringContainsString('/app/incidents', $opened['text']);
        $this->assertStringContainsString('<b>Normalizado: Checkout API</b>', $resolved['text']);
        $this->assertStringContainsString('12 min (14:03–14:15)', $resolved['text']);

        $this->assertSame(['sent', 'sent'], NotificationDelivery::query()->where('channel_id', $channel->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_english_status_page_gets_english_text_and_html_is_escaped(): void
    {
        DB::table('status_pages')->where('environment_id', $this->account->environment->id)->update(['locale' => 'en', 'timezone' => 'UTC']);
        $channel = $this->channel();

        $this->send($channel, ['event' => 'opened', 'monitor_name' => '<script>x</script> & API', 'summary' => '<b>boom</b>', 'started_at' => '2026-11-12T17:03:00Z']);

        $text = $this->api->bodies()[0]['text'];
        $this->assertStringContainsString('Down: &lt;script&gt;x&lt;/script&gt; &amp; API', $text);
        $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $text);
        $this->assertStringContainsString('Since Nov 12, 17:03 (UTC)', $text);
        $this->assertStringNotContainsString('<script>', $text);
    }

    public function test_a_group_that_became_a_supergroup_is_followed_once(): void
    {
        $channel = $this->channel(['chat_id' => -4001, 'chat_type' => 'group', 'chat_title' => 'Plantão']);
        $this->api->queue(400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1009999]]);
        $this->api->queue(200, ['ok' => true, 'result' => ['message_id' => 1]]);

        $result = $this->send($channel, ['event' => 'test']);

        $this->assertSame('sent', $result['status']);
        $this->assertSame([-4001, -1009999], array_column($this->api->bodies(), 'chat_id'));
        $this->assertSame(-1009999, $channel->fresh()->config_json['chat_id']);
        $this->assertSame('supergroup', $channel->fresh()->config_json['chat_type']);
    }

    public function test_telegram_errors_are_recorded(): void
    {
        $channel = $this->channel();

        foreach ([
            [403, 'Forbidden: bot was blocked by the user', [], 'Telegram 403: Forbidden: bot was blocked by the user'],
            [400, 'Bad Request: chat not found', [], 'Telegram 400: Bad Request: chat not found'],
            [429, 'Too Many Requests: retry after 7', ['retry_after' => 7], '(retry_after 7s)'],
        ] as [$code, $description, $parameters, $expected]) {
            $this->api->queue($code, ['ok' => false, 'error_code' => $code, 'description' => $description, 'parameters' => $parameters]);

            $result = $this->send($channel, ['event' => 'test']);

            $this->assertSame('failed', $result['status']);
            $this->assertSame($code, $result['response_code']);
            $this->assertStringContainsString($expected, $result['error_excerpt']);
        }
        $this->assertCount(3, $this->api->requests, 'no retries');
    }

    public function test_the_bot_token_never_leaks(): void
    {
        $channel = $this->channel();
        Log::spy();
        // A transport error message that carries the request URL, as cURL errors do.
        $this->api->failWithUrl = true;

        $key = app(ApiKeyService::class)->create($this->account->tenant, $this->account->user, 'ops', ['monitors:operate', 'monitors:read'], $this->account->environment)['plaintext'];
        $response = $this->withHeaders(['Authorization' => 'Bearer '.$key])
            ->postJson($this->channelsUrl().'/'.$channel->public_id.'/test');

        $delivery = NotificationDelivery::query()->where('channel_id', $channel->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('<redacted>', (string) $delivery->error_excerpt);
        $this->assertStringNotContainsString(self::TOKEN, (string) $delivery->error_excerpt);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode(NotificationChannel::query()->get()->toArray()));
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => str_contains((string) $m, self::TOKEN))]);
    }

    public function test_without_a_token_nothing_is_sent(): void
    {
        config(['telegram.bot_token' => null]);

        $result = $this->send($this->channel(), ['event' => 'test']);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('Telegram is not configured on this installation.', $result['error_excerpt']);
        $this->assertCount(0, $this->api->requests);
    }

    public function test_the_test_button_works_for_a_telegram_channel(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->account->user)
            ->postJson($this->channelsUrl().'/'.$channel->public_id.'/test')
            ->assertSuccessful();

        $this->assertStringContainsString('Teste do StatusConnect', $this->api->bodies()[0]['text']);
        $this->assertStringContainsString('ambiente Production', $this->api->bodies()[0]['text']);
    }

    public function test_telegram_channels_are_not_created_or_rewired_through_the_api(): void
    {
        $this->actingAs($this->account->user)
            ->postJson($this->channelsUrl(), ['name' => 'TG', 'type' => 'telegram', 'config_json' => ['chat_id' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $channel = $this->channel();
        $url = $this->channelsUrl().'/'.$channel->public_id;

        $this->actingAs($this->account->user)
            ->putJson($url, ['config_json' => ['chat_id' => 42]])
            ->assertStatus(422)->assertJsonValidationErrors('config_json');
        $this->actingAs($this->account->user)
            ->putJson($url, ['type' => 'webhook'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        // Sending back what was read, as the SPA's edit form does, only renames.
        $read = $this->actingAs($this->account->user)->getJson($url)->json('data');
        $this->actingAs($this->account->user)
            ->putJson($url, ['name' => 'Plantão', 'type' => $read['type'], 'config_json' => $read['config_json'], 'enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Plantão')
            ->assertJsonPath('data.enabled', false);
        $this->assertSame(-1001234567890, $channel->fresh()->config_json['chat_id']);
    }

    /** @param array<string, mixed>|null $config */
    private function channel(?array $config = null): NotificationChannel
    {
        return NotificationChannel::query()->create([
            'tenant_id' => $this->account->tenant->id,
            'environment_id' => $this->account->environment->id,
            'name' => 'Telegram — Plantão Infra',
            'type' => 'telegram',
            'config_json' => $config ?? ['chat_id' => -1001234567890, 'chat_type' => 'supergroup', 'chat_title' => 'Plantão Infra'],
            'enabled' => true,
        ]);
    }

    private function monitor(string $name): Monitor
    {
        return Monitor::query()->create([
            'tenant_id' => $this->account->tenant->id,
            'environment_id' => $this->account->environment->id,
            'name' => $name,
            'kind' => 'http',
            'target' => 'https://checkout.example.test',
        ]);
    }

    /** @return array{status: string, response_code: ?int, error_excerpt: ?string} */
    private function send(NotificationChannel $channel, array $payload): array
    {
        return app(\App\Domain\Notifications\Transports\TelegramNotificationTransport::class)->send($channel, $payload);
    }

    private function channelsUrl(): string
    {
        return '/v1/tenants/'.$this->account->tenant->public_id.'/environments/'.$this->account->environment->public_id.'/notification-channels';
    }
}
