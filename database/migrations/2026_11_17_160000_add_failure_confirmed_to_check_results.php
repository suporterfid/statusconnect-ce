<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Availability counts only confirmed failures (#237).
 *
 * failure_confirmed is null on up (and other non-failure) results. On a
 * down/degraded result it says whether the failure streak reached the
 * monitor's confirmation threshold.
 *
 * Backfill of the raw results still kept: a failure inside an automatic
 * incident window was confirmed, any other failure was not. The minute cron
 * rewrites the rollups of those days afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->boolean('failure_confirmed')->nullable()->after('void_reason');
        });

        DB::table('check_results')->whereIn('state', ['down', 'degraded'])->update(['failure_confirmed' => false]);

        DB::table('incidents')
            ->where('manual', false)
            ->whereNotNull('monitor_id')
            ->orderBy('id')
            ->chunk(200, function ($incidents): void {
                foreach ($incidents as $incident) {
                    DB::table('check_results')
                        ->where('monitor_id', $incident->monitor_id)
                        ->whereIn('state', ['down', 'degraded'])
                        ->where('checked_at', '>=', $incident->started_at)
                        ->when($incident->resolved_at !== null, fn ($q) => $q->where('checked_at', '<=', $incident->resolved_at))
                        ->update(['failure_confirmed' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropColumn('failure_confirmed');
        });
    }
};
