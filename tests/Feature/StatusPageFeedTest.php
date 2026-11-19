<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageFeedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Monitor $monitor;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $this->monitor = app(MonitorService::class)->createMonitor($tenant, $environment, [
            'name' => 'API',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200?secret=do-not-leak',
        ]);
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($this->owner)->putJson($this->base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
        $this->actingAs($this->owner)->postJson("{$this->base}/components", ['name' => 'Storefront', 'monitor_ids' => [$this->monitor->public_id]])->assertCreated();
    }

    public function test_feed_lists_public_updates_and_incidents_as_valid_atom(): void
    {
        $updateId = $this->actingAs($this->owner)->postJson("{$this->base}/updates", [
            'status' => 'investigating',
            'impact' => 'major_outage',
            'title' => 'Checkout errors',
            'body' => 'Payments fail <script>alert(1)</script> & retry',
        ])->assertCreated()->json('data.id');
        DB::table('incidents')->insert([
            'public_id' => 'inc_01JFEEDINCIDENT00000000000',
            'tenant_id' => $this->monitor->tenant_id,
            'environment_id' => $this->monitor->environment_id,
            'monitor_id' => $this->monitor->id,
            'started_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
            'severity' => 'major',
            'summary' => 'internal summary must stay private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('status_page_cache')->delete();
        $this->artisan('monitor:rollup')->assertSuccessful();
        auth()->logout();

        $response = $this->get('/status/acme-status/feed');
        $response->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8');
        $body = $response->getContent();
        $this->assertStringNotContainsString('internal summary must stay private', $body);
        $this->assertStringNotContainsString('do-not-leak', $body);
        $this->assertStringNotContainsString('<script>', $body);

        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        $this->assertSame('Acme Status', (string) $xml->title);
        $this->assertCount(2, $xml->entry);
        $titles = array_map(static fn ($e) => (string) $e->title, iterator_to_array($xml->entry, false));
        $this->assertContains('[Investigating] Checkout errors', $titles);
        $this->assertContains('Major outage: Storefront', $titles);

        $update = collect(iterator_to_array($xml->entry, false))->first(fn ($e) => str_contains((string) $e->title, 'Checkout'));
        $this->assertSame('Payments fail <script>alert(1)</script> & retry', (string) $update->content);
        $this->assertStringEndsWith('/update/'.$updateId, (string) $update->id);

        $this->get('/status/acme-status')->assertSee('application/atom+xml', false)->assertSee('/status/acme-status/feed');
    }

    public function test_feed_follows_page_visibility(): void
    {
        $this->get('/status/unknown/feed')->assertNotFound();
        DB::table('status_pages')->update(['visibility' => 'private']);
        auth()->logout();
        $this->get('/status/acme-status/feed')->assertNotFound();
    }

    public function test_empty_feed_is_still_valid(): void
    {
        auth()->logout();
        $xml = simplexml_load_string($this->get('/status/acme-status/feed')->assertOk()->getContent());

        $this->assertNotFalse($xml);
        $this->assertCount(0, $xml->entry);
        $this->assertNotSame('', (string) $xml->updated);
    }
}
