<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second-location confirmation (#128).
 *
 * check_results.confirmation_* records what a second location saw when a
 * failure was about to open an incident. monitors.second_location_up_until
 * holds a "seen up from elsewhere" verdict for a short cooldown, so a target
 * that only fails from this server is not re-confirmed on every tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->string('confirmation_state', 16)->nullable()->after('failure_excerpt');
            $table->string('confirmation_provider', 32)->nullable()->after('confirmation_state');
            $table->string('confirmation_location', 120)->nullable()->after('confirmation_provider');
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->timestamp('second_location_up_until')->nullable()->after('first_failure_at');
        });
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropColumn(['confirmation_state', 'confirmation_provider', 'confirmation_location']);
        });

        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('second_location_up_until');
        });
    }
};
