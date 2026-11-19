<?php

namespace Tests\Feature;

use App\Application\Tenancy\TenantService;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\TenantMembership;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StatusPageUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Environment $environment;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->tenant = app(TenantService::class)->createTenant('Acme', 'acme', $this->owner);
        $this->environment = $this->tenant->environments()->firstOrFail();
        $this->base = "/v1/tenants/{$this->tenant->public_id}/environments/{$this->environment->public_id}/status-page";

        $this->actingAs($this->owner)
            ->putJson($this->base, ['slug' => 'acme-status', 'title' => 'Acme Status', 'locale' => 'en'])
            ->assertCreated();
    }

    public function test_owner_publishes_an_update_that_is_public_immediately(): void
    {
        $update = $this->actingAs($this->owner)->postJson("{$this->base}/updates", [
            'status' => 'investigating',
            'impact' => 'major_outage',
            'title' => 'Checkout errors',
            'body' => "We are looking into failed payments.\n<script>alert(1)</script>",
        ])->assertCreated()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.impact', 'major_outage')
            ->json('data');

        $this->assertStringStartsWith('spu_', $update['id']);

        $this->get('/status/acme-status.json')
            ->assertOk()
            ->assertJsonPath('updates.0.title', 'Checkout errors')
            ->assertJsonPath('updates.0.status', 'investigating')
            ->assertJsonPath('update_history.0.title', 'Checkout errors');

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('Status updates')
            ->assertSee('Checkout errors')
            ->assertSee('Investigating')
            ->assertSee('We are looking into failed payments.<br', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('/status/acme-status/history');

        $this->get('/status/acme-status/history')
            ->assertOk()
            ->assertSee('Status update history')
            ->assertSee('Checkout errors');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'status_update.published',
            'resource_id' => $update['id'],
            'actor_user_id' => $this->owner->id,
        ]);
    }

    public function test_edit_and_delete_rerender_the_public_page(): void
    {
        $id = $this->actingAs($this->owner)->postJson("{$this->base}/updates", [
            'status' => 'investigating',
            'title' => 'Slow dashboard',
        ])->assertCreated()->json('data.id');

        $this->putJson("{$this->base}/updates/{$id}", ['status' => 'resolved', 'body' => 'Fixed.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.title', 'Slow dashboard');
        $this->get('/status/acme-status.json')->assertJsonPath('updates.0.status', 'resolved');

        $this->deleteJson("{$this->base}/updates/{$id}")->assertNoContent();
        $this->get('/status/acme-status.json')->assertJsonPath('updates', []);
        $this->get('/status/acme-status')->assertSee('No updates in the last 30 days.');

        $this->assertSame(
            ['status_update.published', 'status_update.edited', 'status_update.deleted'],
            DB::table('audit_logs')->where('resource_type', 'status_page_update')->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_viewer_can_list_but_not_write(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/updates", ['status' => 'notice', 'title' => 'Hello'])->assertCreated();
        $viewer = User::factory()->create();
        TenantMembership::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $viewer->id, 'role' => 'viewer']);

        $this->actingAs($viewer)->getJson("{$this->base}/updates")->assertOk()->assertJsonPath('data.0.title', 'Hello');
        $this->actingAs($viewer)->postJson("{$this->base}/updates", ['status' => 'notice', 'title' => 'Nope'])->assertForbidden();
    }

    public function test_validation_and_tenant_isolation(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/updates", ['status' => 'bogus', 'title' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'title']);

        $other = User::factory()->create();
        $otherTenant = app(TenantService::class)->createTenant('Other', 'other', $other);
        $otherEnv = $otherTenant->environments()->firstOrFail();
        $otherBase = "/v1/tenants/{$otherTenant->public_id}/environments/{$otherEnv->public_id}/status-page";
        $this->actingAs($other)->putJson($otherBase, ['slug' => 'other-status', 'title' => 'Other'])->assertCreated();
        $foreignId = $this->actingAs($other)->postJson("{$otherBase}/updates", ['status' => 'notice', 'title' => 'Theirs'])->json('data.id');

        $this->actingAs($this->owner)->putJson("{$this->base}/updates/{$foreignId}", ['title' => 'Hijack'])->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("{$this->base}/updates/{$foreignId}")->assertNotFound();
        $this->actingAs($this->owner)->getJson("{$otherBase}/updates")->assertNotFound();
    }

    public function test_main_page_shows_only_the_last_30_days_and_history_shows_older(): void
    {
        $pageId = (int) DB::table('status_pages')->where('slug', 'acme-status')->value('id');
        DB::table('status_page_updates')->insert([
            'public_id' => 'spu_01JOLDUPDATE000000000000000',
            'status_page_id' => $pageId,
            'status' => 'resolved',
            'impact' => 'none',
            'title' => 'Old outage report',
            'published_at' => now()->subDays(45),
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ]);
        DB::table('status_pages')->where('id', $pageId)->update(['updated_at' => now()->addSecond()]);
        $this->artisan('monitor:rollup')->assertSuccessful();

        $this->get('/status/acme-status')->assertOk()->assertDontSee('Old outage report')->assertSee('Status update history');
        $this->get('/status/acme-status/history')->assertOk()->assertSee('Old outage report');
    }

    public function test_internal_incident_update_text_never_reaches_the_public_updates(): void
    {
        $this->actingAs($this->owner)->postJson("{$this->base}/updates", ['status' => 'notice', 'title' => 'Public note'])->assertCreated();

        $json = $this->get('/status/acme-status.json')->assertOk()->json();
        foreach ($json['updates'] as $update) {
            $this->assertSame(['id', 'status', 'impact', 'title', 'body', 'published_at', 'edited_at'], array_keys($update));
        }
    }

    public function test_history_of_unknown_or_private_page_is_not_found(): void
    {
        $this->get('/status/nope/history')->assertNotFound();
        DB::table('status_pages')->update(['visibility' => 'private']);
        auth()->logout();
        $this->get('/status/acme-status/history')->assertNotFound();
    }
}
