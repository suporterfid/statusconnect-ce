<?php

namespace Tests\Feature;

use App\Application\Scheduling\CheckerGapRecorder;
use App\Application\StatusPages\StatusPageService;
use App\Application\Tenancy\AccountProvisioningService;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\Persistence\Eloquent\Monitor;
use App\Infrastructure\Persistence\Eloquent\SystemHeartbeat;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Mail\AccountMail;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Checker gaps (#199): noticed on the next tick, shown as no data, watchable from outside. */
class CheckerGapTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-10 20:12:00';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->app->instance(Clock::class, new FrozenClock(new DateTimeImmutable(self::NOW.' UTC')));
    }

    public function test_a_late_tick_records_the_gap_and_emails_the_platform_admins(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        User::factory()->create(['is_platform_admin' => false]);
        $this->heartbeat('2026-11-10 14:51:00');

        app(CheckerGapRecorder::class)->beforeTick();

        $gap = DB::table('checker_gaps')->sole();
        $this->assertSame('2026-11-10 14:51:00', (string) $gap->started_at);
        $this->assertSame(self::NOW, (string) $gap->ended_at);
        Mail::assertSent(AccountMail::class, 1);
        Mail::assertSent(AccountMail::class, fn (AccountMail $mail) => $mail->kind === 'admin_checker_gap'
            && $mail->hasTo($admin->email)
            && $mail->data['minutes'] === 321);
    }

    public function test_a_tick_on_time_or_the_first_tick_records_nothing(): void
    {
        app(CheckerGapRecorder::class)->beforeTick();
        $this->heartbeat('2026-11-10 20:10:30');
        app(CheckerGapRecorder::class)->beforeTick();

        $this->assertSame(0, DB::table('checker_gaps')->count());
        Mail::assertNothingSent();
    }

    public function test_the_check_due_command_notices_the_gap(): void
    {
        $this->heartbeat('2026-11-10 20:00:00');

        $this->artisan('monitor:check-due')->assertSuccessful();

        $this->assertSame(1, DB::table('checker_gaps')->count());
    }

    public function test_seconds_by_day_split_a_gap_at_midnight(): void
    {
        DB::table('checker_gaps')->insert(['started_at' => '2026-11-09 22:00:00', 'ended_at' => '2026-11-10 01:30:00']);

        $this->assertSame(
            ['2026-11-09' => 7200, '2026-11-10' => 5400],
            CheckerGapRecorder::secondsByDay(new DateTimeImmutable('2026-11-01 00:00:00 UTC'), new DateTimeImmutable('2026-11-11 00:00:00 UTC')),
        );
    }

    public function test_health_endpoint_reports_the_checker_only_when_asked(): void
    {
        // The route reads the real time, not the frozen clock.
        $this->get('/v1/platform/health')->assertOk()->assertJsonPath('status', 'ok')->assertJsonMissingPath('checker');
        $this->get('/v1/platform/health?checker=1')->assertStatus(503)
            ->assertJsonPath('status', 'checker_stopped')
            ->assertJsonPath('checker.last_tick_at', null);

        $this->heartbeat(now()->subSeconds(30)->format('Y-m-d H:i:s'));
        $this->get('/v1/platform/health?checker=1')->assertOk()->assertJsonPath('checker.ok', true);

        $this->heartbeat(now()->subMinutes(10)->format('Y-m-d H:i:s'));
        $this->get('/v1/platform/health')->assertOk();
        $this->get('/v1/platform/health?checker=1')->assertStatus(503)->assertJsonPath('checker.age_seconds', 600);
    }

    public function test_the_status_page_shows_the_gap_as_time_without_checks(): void
    {
        // The cache writer reads the real time: build today's data around it.
        $this->app->instance(Clock::class, new \App\Domain\Shared\SystemClock);
        $account = app(AccountProvisioningService::class)->provision([
            'email' => 'owner@acme.test', 'name' => 'Owner', 'org' => 'Acme', 'slug' => 'acme',
        ]);
        $monitor = Monitor::query()->create([
            'tenant_id' => $account->tenant->id, 'environment_id' => $account->environment->id,
            'name' => 'Site', 'kind' => 'http', 'target' => 'https://example.test',
        ]);
        app(StatusPageService::class)->createComponent($account->environment, ['name' => 'Site', 'monitor_ids' => [$monitor->public_id]]);
        \App\Infrastructure\Persistence\Eloquent\CheckResult::query()->create([
            'tenant_id' => $account->tenant->id, 'environment_id' => $account->environment->id, 'monitor_id' => $monitor->id,
            'state' => 'up', 'latency_ms' => 100, 'checked_at' => now()->subSeconds(5),
        ]);
        $midnight = now()->utc()->startOfDay();
        $gapSeconds = (int) min(3600, $midnight->diffInSeconds(now()->utc()->subSeconds(10), true));
        DB::table('checker_gaps')->insert(['started_at' => $midnight, 'ended_at' => $midnight->copy()->addSeconds($gapSeconds)]);

        app(StatusPageService::class)->rerender((int) DB::table('status_pages')->value('id'));

        $today = $this->get('/status/acme.json')->assertOk()->json('components.0.history.89');
        $this->assertSame($gapSeconds, $today['no_data_seconds']);
        if ($gapSeconds >= 60) {
            $this->get('/status/acme')->assertOk()->assertSee(__('status.day_gap', ['duration' => '']), false);
        }
    }

    private function heartbeat(string $at): void
    {
        SystemHeartbeat::query()->updateOrCreate(['name' => 'checker'], ['last_seen_at' => $at, 'meta_json' => []]);
    }
}
