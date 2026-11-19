<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\StatusPages\Subscriptions\SubscriberNotifier;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\StatusPageSubscriberMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Email subscriptions to status pages (#92).
 */
class StatusPageSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Monitor $monitor;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = app(MonitorService::class)->createMonitor($tenant, $environment, [
            'name' => 'API', 'kind' => 'http', 'target' => 'http://target:8095/status/200',
        ]);
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($this->owner)->putJson($this->base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en', 'timezone' => 'America/Sao_Paulo'])->assertCreated();
        $this->actingAs($this->owner)->postJson("{$this->base}/components", ['name' => 'Storefront', 'monitor_ids' => [$this->monitor->public_id]])->assertCreated();
        auth()->guard('web')->logout();
    }

    private function enable(bool $on = true): void
    {
        $this->actingAs($this->owner)->putJson($this->base, ['subscriptions_enabled' => $on])->assertOk()->assertJsonPath('data.subscriptions_enabled', $on);
        auth()->guard('web')->logout();
    }

    private function subscribe(string $email = 'Fan@Example.com')
    {
        return $this->post('/status/acme-status/subscribe', ['email' => $email]);
    }

    /** Subscribes and confirms; returns the subscriber row. */
    private function confirmedSubscriber(string $email = 'fan@example.com'): object
    {
        $this->subscribe($email)->assertOk();
        $url = null;
        Mail::assertSent(StatusPageSubscriberMail::class, function (StatusPageSubscriberMail $m) use ($email, &$url) {
            if ($m->kind === 'confirm' && $m->hasTo($email)) {
                $url = $m->data['url'];

                return true;
            }

            return false;
        });
        $path = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($path)->assertOk()->assertSee('Confirm subscription');
        $this->post($path)->assertOk()->assertSee('Subscription confirmed');

        return DB::table('status_page_subscribers')->where('email', $email)->first();
    }

    private function incident(array $overrides = []): int
    {
        return DB::table('incidents')->insertGetId($overrides + [
            'public_id' => 'inc_'.uniqid(),
            'tenant_id' => $this->monitor->tenant_id,
            'environment_id' => $this->monitor->environment_id,
            'monitor_id' => $this->monitor->id,
            'started_at' => now()->subMinutes(3),
            'severity' => 'major',
            'summary' => 'internal summary must stay private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tick(): void
    {
        $this->artisan('monitor:maintenance')->assertSuccessful();
    }

    public function test_form_only_on_pages_with_subscriptions_enabled(): void
    {
        $this->artisan('monitor:rollup');
        $this->get('/status/acme-status')->assertOk()->assertDontSee('Get updates by email');
        $this->subscribe()->assertNotFound();

        $this->enable();
        $this->artisan('monitor:rollup');
        $this->get('/status/acme-status')->assertOk()->assertSee('Get updates by email')
            ->assertSee('action="'.rtrim(config('app.url'), '/').'/status/acme-status/subscribe"', false);

        DB::table('status_pages')->update(['visibility' => 'private']);
        $this->subscribe()->assertNotFound();
    }

    public function test_double_opt_in_and_same_answer_for_everyone(): void
    {
        $this->enable();

        $first = $this->subscribe()->assertOk()->getContent();
        $this->assertSame('fan@example.com', DB::table('status_page_subscribers')->value('email'));
        Mail::assertSent(StatusPageSubscriberMail::class, 1);

        // Repeat within 10 minutes: no second email, same page.
        $this->assertSame(strip_tags($first), strip_tags($this->subscribe()->assertOk()->getContent()));
        Mail::assertSent(StatusPageSubscriberMail::class, 1);

        // Unconfirmed subscribers get nothing.
        $this->tick();
        $this->incident();
        $this->travel(1)->minutes();
        $this->tick();
        Mail::assertSent(StatusPageSubscriberMail::class, 1);

        // Bad and tampered links.
        $sub = DB::table('status_page_subscribers')->first();
        $this->get("/status/acme-status/subscriptions/{$sub->public_id}/confirm")->assertOk()->assertSee('Invalid or expired link');
        $this->post("/status/acme-status/subscriptions/{$sub->public_id}/confirm?expires=9999999999&signature=x")->assertOk()->assertSee('Invalid or expired link');
        $this->assertNull(DB::table('status_page_subscribers')->value('confirmed_at'));

        // Honeypot and invalid email.
        $this->post('/status/acme-status/subscribe', ['email' => 'bot@example.com', 'website' => 'x'])->assertOk();
        $this->assertSame(1, DB::table('status_page_subscribers')->count());
        $this->post('/status/acme-status/subscribe', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        // Five subscribe posts per ten minutes per IP; the confirmation pages have their own limit.
        $this->subscribe('other@example.com')->assertOk();
        $this->subscribe('third@example.com')->assertStatus(429);
    }

    public function test_incident_and_update_notifications_reach_confirmed_subscribers_once(): void
    {
        $this->enable();
        $this->tick(); // first run starts the page's clock
        $sub = $this->confirmedSubscriber();
        $late = null;

        $this->travel(1)->minutes();
        $incidentId = $this->incident();
        $this->actingAs($this->owner)->postJson("{$this->base}/updates", [
            'status' => 'investigating', 'impact' => 'major_outage', 'title' => 'Checkout errors', 'body' => 'We are looking into it.',
        ])->assertCreated();
        auth()->guard('web')->logout();
        $this->travel(1)->minutes();
        $this->tick();

        Mail::assertSent(StatusPageSubscriberMail::class, fn (StatusPageSubscriberMail $m) => $m->kind === 'incident'
            && $m->hasTo('fan@example.com')
            && $m->subjectLine === '[Acme Status] Incident: Storefront'
            && str_contains($m->unsubscribeUrl, '/unsubscribe/'));
        Mail::assertSent(StatusPageSubscriberMail::class, fn (StatusPageSubscriberMail $m) => $m->kind === 'update' && $m->subjectLine === '[Acme Status] Checkout errors');

        // Render the mail that was actually sent: the opened notice must not read as a resolution.
        $opened = Mail::sent(StatusPageSubscriberMail::class, fn (StatusPageSubscriberMail $m) => $m->kind === 'incident')->first()->render();
        $this->assertStringContainsString('Major outage', $opened);
        $this->assertStringNotContainsString('Operational', $opened);

        $rendered = (new StatusPageSubscriberMail('incident', 's', [
            'incidentKind' => 'incident_opened', 'pageTitle' => 'Acme Status', 'pageUrl' => 'u', 'components' => 'Storefront',
            'startedAt' => 'x', 'resolvedAt' => null, 'publishedAt' => null, 'duration' => null, 'timezone' => 'America/Sao_Paulo',
            'title' => null, 'body' => null, 'status' => null,
        ], 'https://example.test/unsub'))->render();
        $this->assertStringNotContainsString('internal summary', $rendered);
        $this->assertStringContainsString('https://example.test/unsub', $rendered);

        // Resolution, then nothing is sent twice.
        DB::table('incidents')->where('id', $incidentId)->update(['resolved_at' => now(), 'updated_at' => now()]);
        $this->travel(1)->minutes();
        $this->tick();
        $this->tick();
        Mail::assertSent(StatusPageSubscriberMail::class, fn (StatusPageSubscriberMail $m) => $m->subjectLine === '[Acme Status] Resolved: Storefront');
        $this->assertSame(3, DB::table('status_page_notifications')->count());
        $this->assertSame(4, count(Mail::sent(StatusPageSubscriberMail::class))); // confirm + 3 notices
    }

    public function test_enabling_does_not_mail_the_past_and_private_monitors_stay_out(): void
    {
        $this->incident(['created_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(30), 'resolved_flag' => null]);
        $this->enable();
        $this->confirmedSubscriber();
        $this->tick();
        $this->travel(2)->minutes();
        $this->tick();
        $this->assertSame(0, DB::table('status_page_notifications')->count());

        Monitor::query()->whereKey($this->monitor->id)->update(['public_safe' => false]);
        $this->incident();
        $this->travel(2)->minutes();
        $this->tick();
        $this->assertSame(0, DB::table('status_page_notifications')->count());
    }

    public function test_unsubscribe_link_and_one_click_post(): void
    {
        $this->enable();
        $sub = $this->confirmedSubscriber();
        $url = app(\App\Application\StatusPages\Subscriptions\SubscriptionService::class)->unsubscribeUrl(DB::table('status_pages')->first(), $sub);
        $path = parse_url($url, PHP_URL_PATH);

        $this->get(preg_replace('#/[^/]+$#', '/wrongtoken', $path))->assertOk()->assertSee('Unsubscribed');
        $this->assertSame(1, DB::table('status_page_subscribers')->count());

        $this->get($path)->assertOk()->assertSee('Stop getting Acme Status updates');
        // One-click (RFC 8058): no CSRF token, works after subscriptions are turned off.
        $this->enable(false);
        $this->post($path, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Unsubscribed');
        $this->assertSame(0, DB::table('status_page_subscribers')->count());
    }

    public function test_api_shows_count_not_addresses_and_limits_apply(): void
    {
        $this->enable();
        $this->confirmedSubscriber();
        $this->actingAs($this->owner)->getJson($this->base)->assertOk()
            ->assertJsonPath('data.subscriber_count', 1)
            ->assertDontSee('fan@example.com');
        auth()->guard('web')->logout();

        config(['status_page.subscriptions.new_per_page_per_hour' => 1]);
        Cache::flush();
        $this->subscribe('a@example.com')->assertOk();
        $this->subscribe('b@example.com')->assertOk();
        $this->assertSame(['a@example.com', 'fan@example.com'], DB::table('status_page_subscribers')->orderBy('email')->pluck('email')->all());

        // Never-confirmed subscriptions are deleted after the link expires.
        $this->travel(50)->hours();
        $this->tick();
        $this->assertSame(['fan@example.com'], DB::table('status_page_subscribers')->pluck('email')->all());
    }

    public function test_delivery_respects_the_per_minute_budget_and_resumes(): void
    {
        config(['status_page.subscriptions.mail_per_minute' => 2]);
        $this->enable();
        $this->tick();
        $page = DB::table('status_pages')->first();
        foreach (range(1, 5) as $i) {
            DB::table('status_page_subscribers')->insert([
                'public_id' => "sub_{$i}", 'status_page_id' => $page->id, 'email' => "s{$i}@example.com",
                'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->travel(1)->minutes();
        $this->incident();
        $this->travel(1)->minutes();

        $notifier = app(SubscriberNotifier::class);
        $notifier->collect();
        $this->assertSame(2, $notifier->deliver(2));
        $this->assertSame(2, $notifier->deliver(2));
        $this->assertSame(1, $notifier->deliver(2));
        $this->assertSame(0, $notifier->deliver(2));
        $this->assertNotNull(DB::table('status_page_notifications')->value('completed_at'));
        Mail::assertSent(StatusPageSubscriberMail::class, 5);
    }
}
