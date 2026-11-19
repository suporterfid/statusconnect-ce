<?php

namespace Tests\Feature;

use App\Application\Monitoring\MonitorService;
use App\Application\Tenancy\TenantService;
use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Environment $environment;

    private Monitor $monitor;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->environment = $this->tenant->environments()->firstOrFail();
        $this->monitor = app(MonitorService::class)->createMonitor($this->tenant, $this->environment, [
            'name' => 'API',
            'kind' => 'http',
            'target' => 'http://target:8095/status/200?secret=do-not-leak',
        ]);
        $this->base = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}";
    }

    public function test_owner_creates_page_and_component_and_they_are_public_immediately(): void
    {
        $this->actingAs($this->owner);

        $this->getJson("{$this->base}/status-page")->assertOk()->assertJsonPath('data', null);

        $this->putJson("{$this->base}/status-page", ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'pt-BR'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'acme-status')
            ->assertJsonPath('data.public_url', url('/status/acme-status'));

        $component = $this->postJson("{$this->base}/status-page/components", [
            'name' => 'Storefront',
            'monitor_ids' => [$this->monitor->public_id],
        ])->assertCreated()->assertJsonPath('data.monitor_ids', [$this->monitor->public_id])->json('data');

        // No monitor:rollup run: the write itself re-renders the snapshot.
        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('Acme Status')
            ->assertSee('Storefront')
            ->assertDontSee('do-not-leak')
            ->assertDontSee($this->monitor->public_id);

        $this->putJson("{$this->base}/status-page/components/{$component['id']}", ['name' => 'Checkout'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Checkout');
        $this->get('/status/acme-status')->assertSee('Checkout')->assertDontSee('Storefront');

        $this->deleteJson("{$this->base}/status-page/components/{$component['id']}")->assertNoContent();
        $this->getJson("{$this->base}/status-page/components")->assertOk()->assertJsonCount(0, 'data');
        $this->get('/status/acme-status')->assertDontSee('Checkout');
    }

    public function test_bcp47_locale_is_stored_as_laravel_locale_and_published(): void
    {
        $this->actingAs($this->owner);

        $this->putJson("{$this->base}/status-page", ['slug' => 'acme-status', 'title' => 'Acme', 'locale' => 'pt-BR'])
            ->assertCreated()
            ->assertJsonPath('data.locale', 'pt_BR');

        $this->getJson('/status/acme-status.json')->assertOk()->assertJsonPath('locale', 'pt_BR');
    }

    public function test_updating_the_page_keeps_unsent_fields(): void
    {
        $this->actingAs($this->owner);
        $this->putJson("{$this->base}/status-page", ['slug' => 'acme-status', 'title' => 'Acme'])->assertCreated();

        $this->putJson("{$this->base}/status-page", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'acme-status')
            ->assertJsonPath('data.title', 'Renamed');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $this->actingAs($this->owner);
        $this->putJson("{$this->base}/status-page", ['slug' => 'acme-status', 'title' => 'Acme'])->assertCreated();

        $viewer = User::factory()->create();
        app(TenantService::class)->addMember($this->tenant, $viewer, TenantRole::VIEWER);
        $this->actingAs($viewer);

        $this->getJson("{$this->base}/status-page")->assertOk()->assertJsonPath('data.slug', 'acme-status');
        $this->putJson("{$this->base}/status-page", ['title' => 'Hijacked'])->assertForbidden();
        $this->postJson("{$this->base}/status-page/components", ['name' => 'X'])->assertForbidden();
        $this->assertSame('Acme', DB::table('status_pages')->value('title'));
    }

    public function test_slug_must_be_unique_across_tenants(): void
    {
        $other = User::factory()->create();
        $otherTenant = app(TenantService::class)->createTenant('Other', 'other', $other);
        $otherEnv = $otherTenant->environments()->firstOrFail();
        $this->actingAs($other)
            ->putJson("/v1/tenants/{$otherTenant->public_id}/environments/{$otherEnv->public_id}/status-page", ['slug' => 'taken', 'title' => 'Other'])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->putJson("{$this->base}/status-page", ['slug' => 'taken', 'title' => 'Acme'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    public function test_component_cannot_link_a_monitor_from_another_environment(): void
    {
        $other = User::factory()->create();
        $otherTenant = app(TenantService::class)->createTenant('Other', 'other', $other);
        $otherEnv = $otherTenant->environments()->firstOrFail();
        $foreign = app(MonitorService::class)->createMonitor($otherTenant, $otherEnv, [
            'name' => 'Foreign', 'kind' => 'http', 'target' => 'http://target:8095/status/200',
        ]);

        $this->actingAs($this->owner);
        $this->putJson("{$this->base}/status-page", ['slug' => 'acme-status', 'title' => 'Acme'])->assertCreated();
        $this->postJson("{$this->base}/status-page/components", ['name' => 'X', 'monitor_ids' => [$foreign->public_id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['monitor_ids']);
        $this->assertSame(0, DB::table('status_page_components')->count());
    }

    public function test_user_changes_password(): void
    {
        $user = User::factory()->create(['password' => 'old-password-123']);
        $this->actingAs($user);

        $this->putJson('/v1/me/password', [
            'current_password' => 'wrong', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);

        $this->putJson('/v1/me/password', [
            'current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('new-password-456', $user->fresh()->password));
    }
}
