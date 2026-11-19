<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Infrastructure\Persistence\Eloquent\User;
use Tests\TestCase;

class PublicStatusPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_status_page_returns_a_generic_not_found_response(): void
    {
        $this->get('/status/unknown-page')->assertNotFound();
    }

    public function test_status_page_snapshot_storage_exists(): void
    {
        $this->assertTrue(Schema::hasTable('status_pages'));
        $this->assertTrue(Schema::hasTable('status_page_components'));
        $this->assertTrue(Schema::hasTable('status_page_cache'));
        $this->assertTrue(Schema::hasTable('rate_limit_buckets'));
    }

    public function test_public_page_serves_a_safe_cached_snapshot_in_html_and_json(): void
    {
        $target = 'https://private-api.example.test:8443/health?secret=do-not-leak';
        $monitorPublicId = 'mon_01JSTATUSPAGEPRIVATE';
        $this->cacheSnapshot($target, $monitorPublicId);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/status/acme-status');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $html->assertOk()
            ->assertHeader('ETag', '"safe-snapshot-etag"')
            ->assertSee('Storefront')
            ->assertSee('last 90 days')
            ->assertSee('Incident history')
            ->assertDontSee($target)
            ->assertDontSee('private-api.example.test')
            ->assertDontSee('8443')
            ->assertDontSee($monitorPublicId)
            ->assertDontSee('Internal tenant name');
        $this->assertStringContainsString('public', (string) $html->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=60', (string) $html->headers->get('Cache-Control'));
        $snapshotQueries = array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'status_page_cache'));
        $this->assertCount(1, $snapshotQueries);

        $this->get('/status/acme-status.json')
            ->assertOk()
            ->assertHeader('ETag', '"safe-snapshot-etag"')
            ->assertJsonPath('components.0.name', 'Storefront')
            ->assertDontSee($target)
            ->assertDontSee('private-api.example.test')
            ->assertDontSee($monitorPublicId);

        $this->withHeader('If-None-Match', '"safe-snapshot-etag"')
            ->get('/status/acme-status')
            ->assertStatus(304);
    }

    public function test_public_page_is_rate_limited_by_ip_with_a_database_bucket(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGERATELIMIT');
        config()->set('status_page.public_rate_limit_per_minute', 1);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/status/acme-status')->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->get('/status/acme-status')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertSame(1, DB::table('rate_limit_buckets')->count());
    }

    public function test_rollup_command_pre_renders_a_safe_status_page_snapshot(): void
    {
        $target = 'https://private-api.example.test:8443/health?secret=do-not-leak';
        $monitorPublicId = 'mon_01JSTATUSPAGEWRITER';
        $this->cacheSnapshot($target, $monitorPublicId);
        DB::table('status_page_cache')->delete();

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $cache = DB::table('status_page_cache')->first();
        $this->assertNotNull($cache);
        $this->assertStringContainsString('Storefront', $cache->payload_json);
        $this->assertStringNotContainsString($target, $cache->payload_json);
        $this->assertStringNotContainsString($monitorPublicId, $cache->payload_json);
    }

    public function test_rollup_does_not_publish_a_monitor_from_another_environment(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGECROSSENV');
        $tenantId = (int) DB::table('tenants')->value('id');
        $otherEnvironmentId = DB::table('environments')->insertGetId([
            'public_id' => 'env_01JSTATUSPAGEOTHER',
            'tenant_id' => $tenantId,
            'name' => 'Other environment',
            'slug' => 'other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('monitors')->update(['environment_id' => $otherEnvironmentId, 'updated_at' => now()->addSecond()]);
        DB::table('status_page_cache')->delete();

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('no_data', $payload['components'][0]['state']);
    }

    public function test_rollup_uses_the_current_partial_bucket_for_uptime(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEPARTIAL');
        $monitor = DB::table('monitors')->first();
        DB::table('status_page_cache')->delete();
        DB::table('check_results')->insert([
            [
                'public_id' => 'res_01JSTATUSPAGEPARTIALUP',
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'monitor_id' => $monitor->id,
                'state' => 'up',
                'checked_at' => now()->subMinutes(2),
                'created_at' => now(),
            ],
            [
                'public_id' => 'res_01JSTATUSPAGEPARTIALDOWN',
                'tenant_id' => $monitor->tenant_id,
                'environment_id' => $monitor->environment_id,
                'monitor_id' => $monitor->id,
                'state' => 'down',
                'checked_at' => now()->subMinute(),
                'created_at' => now(),
            ],
        ]);

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(50.0, $payload['components'][0]['uptime']['24h']);
    }

    public function test_unlisted_page_sends_a_noindex_directive(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEUNLISTED');
        DB::table('status_pages')->update(['visibility' => 'unlisted']);

        $this->get('/status/acme-status')->assertOk()->assertSee('noindex');
    }

    public function test_private_page_requires_a_member_session(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEPRIVATE');
        DB::table('status_pages')->update(['visibility' => 'private']);
        $user = User::factory()->create();
        DB::table('tenant_memberships')->insert([
            'public_id' => 'mem_01JSTATUSPAGEPRIVATE',
            'tenant_id' => DB::table('tenants')->value('id'),
            'user_id' => $user->id,
            'role' => 'viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/status/acme-status')->assertNotFound();
        $this->actingAs($user)->get('/status/acme-status')->assertOk();
    }

    public function test_rollup_includes_safe_incident_update_timeline_entries(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEINCIDENT');
        $monitor = DB::table('monitors')->first();
        DB::table('status_page_cache')->delete();
        $incidentId = DB::table('incidents')->insertGetId([
            'public_id' => 'inc_01JSTATUSPAGEINCIDENT',
            'tenant_id' => $monitor->tenant_id,
            'environment_id' => $monitor->environment_id,
            'monitor_id' => $monitor->id,
            'started_at' => now()->subMinute(),
            'severity' => 'major',
            'summary' => 'private-api.example.test must not be public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('incident_updates')->insert([
            'public_id' => 'inu_01JSTATUSPAGEINCIDENT',
            'incident_id' => $incidentId,
            'status' => 'investigating',
            'message' => 'private-api.example.test must not be public',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('updates', $payload['incidents'][0]);
        $this->assertSame('investigating', $payload['incidents'][0]['updates'][0]['status']);
        $this->assertStringNotContainsString('private-api.example.test', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_rollup_refreshes_a_snapshot_when_page_metadata_changes(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEMETADATA');
        DB::table('status_pages')->update(['title' => 'Renamed status', 'updated_at' => now()->addSecond()]);

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Renamed status', $payload['title']);
    }

    public function test_blade_formats_percentages_and_incident_dates_for_page_locale_and_timezone(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGELOCALE');
        $cache = DB::table('status_page_cache')->first();
        $payload = json_decode($cache->payload_json, true, 512, JSON_THROW_ON_ERROR);
        $payload['locale'] = 'pt_BR';
        $payload['timezone'] = 'America/Sao_Paulo';
        $payload['components'][0]['uptime']['90d'] = 99.5;
        $payload['incidents'] = [[
            'state' => 'major_outage',
            'started_at' => '2026-08-04T12:00:00Z',
            'resolved_at' => null,
            'updates' => [],
        ]];
        DB::table('status_page_cache')->update(['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('99,50%')
            ->assertSee('04/08/2026 09:00')
            ->assertSee('Indisponibilidade grave');
    }

    public function test_rollup_snapshot_includes_overall_uptime_and_daily_detail(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEDAILY');
        $monitor = DB::table('monitors')->first();
        DB::table('status_page_cache')->delete();
        $yesterday = now()->subDay()->startOfDay();
        DB::table('check_rollups')->insert([
            'monitor_id' => $monitor->id,
            'bucket_start' => $yesterday,
            'bucket_kind' => 'day',
            'up_count' => 99,
            'down_count' => 1,
            'downtime_seconds' => 120,
            'checks_total' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('monitor:rollup'));

        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['24h', '7d', '30d', '90d'], array_keys($payload['overall_uptime']));
        $this->assertEquals(99.0, $payload['overall_uptime']['90d']);
        $history = $payload['components'][0]['history'];
        $this->assertCount(90, $history);
        $entry = collect($history)->firstWhere('date', $yesterday->format('Y-m-d'));
        $this->assertSame('major_outage', $entry['state']);
        $this->assertEquals(99.0, $entry['uptime']);
        $this->assertSame(120, $entry['downtime_seconds']);
        $this->assertNull($history[0]['uptime']);
        $this->assertSame('no_data', $history[0]['state']);
    }

    public function test_page_renders_overall_banner_uptime_block_and_day_bars(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGEBARS');
        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $payload['overall_uptime'] = ['24h' => 100.0, '7d' => 99.9, '30d' => 99.95, '90d' => 99.97];
        $payload['components'][0]['history'] = [
            ['date' => '2026-08-03', 'state' => 'no_data', 'uptime' => null, 'downtime_seconds' => 0],
            ['date' => '2026-08-04', 'state' => 'major_outage', 'uptime' => 98.5, 'downtime_seconds' => 3900],
        ];
        DB::table('status_page_cache')->update(['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('All systems operational')
            ->assertSee('Overall uptime')
            ->assertSee('Last 30 days')
            ->assertSee('99.95%')
            ->assertSee('Aug 3, 2026: no data')
            ->assertSee('Aug 4, 2026: 98.50% uptime')
            ->assertSee('1 h 5 min down')
            ->assertSee('No incidents recorded.');

        $payload['locale'] = 'pt_BR';
        DB::table('status_page_cache')->update(['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'etag' => 'pt-etag']);

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('Todos os sistemas operacionais')
            ->assertSee('Disponibilidade geral')
            ->assertSee('04/08/2026: 98,50% de disponibilidade')
            ->assertSee('1 h 5 min fora do ar');
    }

    public function test_page_still_renders_a_snapshot_written_before_the_redesign(): void
    {
        $this->cacheSnapshot('https://safe.example.test/health', 'mon_01JSTATUSPAGELEGACY');
        $payload = json_decode((string) DB::table('status_page_cache')->value('payload_json'), true, 512, JSON_THROW_ON_ERROR);
        $payload['components'][0]['history'] = [['date' => '2026-08-04', 'state' => 'operational']];
        DB::table('status_page_cache')->update(['payload_json' => json_encode($payload, JSON_THROW_ON_ERROR)]);

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSee('Storefront')
            ->assertSee('Aug 4, 2026: Operational')
            ->assertDontSee('Overall uptime');
    }

    private function cacheSnapshot(string $target, string $monitorPublicId): void
    {
        $tenantId = DB::table('tenants')->insertGetId([
            'public_id' => 'ten_01JSTATUSPAGEPRIVATE',
            'name' => 'Internal tenant name',
            'slug' => 'internal-tenant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $environmentId = DB::table('environments')->insertGetId([
            'public_id' => 'env_01JSTATUSPAGEPRIVATE',
            'tenant_id' => $tenantId,
            'name' => 'Internal production environment',
            'slug' => 'production',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $monitorId = DB::table('monitors')->insertGetId([
            'public_id' => $monitorPublicId,
            'tenant_id' => $tenantId,
            'environment_id' => $environmentId,
            'name' => 'Internal monitor name',
            'kind' => 'http',
            'target' => $target,
            'interval_seconds' => 60,
            'current_state' => 'up',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pageId = DB::table('status_pages')->insertGetId([
            'tenant_id' => $tenantId,
            'environment_id' => $environmentId,
            'slug' => 'acme-status',
            'title' => 'Acme Status',
            'visibility' => 'public',
            'locale' => 'en',
            'timezone' => 'UTC',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $componentId = DB::table('status_page_components')->insertGetId([
            'status_page_id' => $pageId,
            'name' => 'Storefront',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('status_page_component_monitors')->insert([
            'status_page_component_id' => $componentId,
            'monitor_id' => $monitorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('status_page_cache')->insert([
            'status_page_id' => $pageId,
            'payload_json' => json_encode([
                'title' => 'Acme Status',
                'locale' => 'en',
                'timezone' => 'UTC',
                'overall_state' => 'operational',
                'components' => [[
                    'name' => 'Storefront',
                    'state' => 'operational',
                    'uptime' => ['24h' => 100.0, '7d' => 100.0, '30d' => 100.0, '90d' => 100.0],
                    'history' => [],
                ]],
                'maintenance' => [],
                'incidents' => [],
                'generated_at' => '2026-08-04T12:00:00Z',
            ], JSON_THROW_ON_ERROR),
            'etag' => 'safe-snapshot-etag',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
