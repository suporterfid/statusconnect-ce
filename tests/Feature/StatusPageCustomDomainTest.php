<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageCustomDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $base;

    private int $componentId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://app.statusconnect.test');

        $this->owner = User::factory()->create();
        $tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $environment = $tenant->environments()->firstOrFail();
        $monitor = app(MonitorService::class)->createMonitor($tenant, $environment, [
            'name' => 'API',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200',
        ]);
        $this->base = "/v1/tenants/{$tenant->public_id}/environments/{$environment->public_id}/status-page";
        $this->actingAs($this->owner)->putJson($this->base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])->assertCreated();
        $this->componentId = $this->actingAs($this->owner)->postJson("{$this->base}/components", ['name' => 'Storefront', 'monitor_ids' => [$monitor->public_id]])
            ->assertCreated()->json('data.id');
    }

    public function test_owner_sets_a_custom_domain_and_the_page_is_served_there(): void
    {
        $this->actingAs($this->owner)->putJson($this->base, ['custom_domain' => 'Status.Acme.Example.'])
            ->assertOk()
            ->assertJsonPath('data.custom_domain', 'status.acme.example')
            ->assertJsonPath('data.custom_url', 'https://status.acme.example');
        auth()->logout();

        $this->get('http://status.acme.example/')
            ->assertOk()
            ->assertSee('Acme Status')
            ->assertSee('href="/components/'.$this->componentId.'"', false)
            ->assertSee('href="/calendar"', false)
            ->assertSee('href="/feed"', false)
            ->assertDontSee('/status/acme-status');

        $this->get('http://status.acme.example/components/'.$this->componentId)->assertOk()->assertSee('Storefront')->assertSee('href="/"', false);
        $this->get('http://status.acme.example/calendar')->assertOk();
        $this->get('http://status.acme.example/history')->assertOk();
        $this->get('http://status.acme.example/status.json')->assertOk()->assertJsonPath('title', 'Acme Status');

        $feed = $this->get('http://status.acme.example/feed')->assertOk()->getContent();
        $this->assertStringContainsString('href="http://status.acme.example/feed"', $feed);
        $this->assertStringNotContainsString('href="https://app.statusconnect.test', $feed);

        $this->get('/status/acme-status')->assertOk()->assertSee('href="/status/acme-status/calendar"', false);
    }

    public function test_operator_routes_and_writes_are_not_reachable_on_a_custom_domain(): void
    {
        DB::table('status_pages')->update(['custom_domain' => 'status.acme.example']);

        $this->get('http://status.acme.example/app/')->assertNotFound();
        $this->get('http://status.acme.example/v1/me')->assertNotFound();
        $this->get('http://status.acme.example/status/acme-status')->assertNotFound();
        $this->get('http://status.acme.example/up')->assertNotFound();
        $this->post('http://status.acme.example/')->assertNotFound();
    }

    public function test_unknown_hosts_fall_through_to_normal_routes(): void
    {
        $this->get('http://unknown.example/status/acme-status')->assertOk();
    }

    public function test_domain_validation_and_uniqueness(): void
    {
        foreach (['not a host', 'https://status.acme.example', 'app.statusconnect.test', 'x.app.statusconnect.test'] as $bad) {
            $this->actingAs($this->owner)->putJson($this->base, ['custom_domain' => $bad])
                ->assertUnprocessable()->assertJsonValidationErrors(['custom_domain']);
        }

        $other = User::factory()->create();
        $otherTenant = app(TenantService::class)->createTenant('Other', 'other', $other);
        $otherEnv = $otherTenant->environments()->firstOrFail();
        $otherBase = "/v1/tenants/{$otherTenant->public_id}/environments/{$otherEnv->public_id}/status-page";
        $this->actingAs($other)->putJson($otherBase, ['slug' => 'other-status', 'title' => 'Other', 'custom_domain' => 'status.shared.example'])->assertCreated();

        $this->actingAs($this->owner)->putJson($this->base, ['custom_domain' => 'status.shared.example'])
            ->assertUnprocessable()->assertJsonValidationErrors(['custom_domain']);

        $this->actingAs($this->owner)->putJson($this->base, ['custom_domain' => 'status.acme.example'])->assertOk();
        $this->actingAs($this->owner)->putJson($this->base, ['custom_domain' => null])->assertOk()->assertJsonPath('data.custom_domain', null);
        auth()->logout();
        // A released domain no longer maps to the page: / falls through to the site's home page.
        $this->get('http://status.acme.example/')->assertOk()->assertSee('/app/login', false)->assertDontSee('Acme Status');
    }

    public function test_private_page_on_custom_domain_is_not_found_for_visitors(): void
    {
        DB::table('status_pages')->update(['custom_domain' => 'status.acme.example', 'visibility' => 'private']);
        auth()->logout();

        $this->get('http://status.acme.example/')->assertNotFound();
    }
}
