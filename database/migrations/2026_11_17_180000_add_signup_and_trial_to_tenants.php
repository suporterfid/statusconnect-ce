<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instant sign-up and the Pro trial (#218).
 *
 * signed_up_at marks organizations created through self-service sign-up: they
 * get the new-account check cap and appear in the daily sign-up digest.
 * The trial columns record the one trial an organization may ever start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestamp('signed_up_at')->nullable()->after('approved_at');
            $table->timestamp('trial_started_at')->nullable()->after('signed_up_at');
            $table->timestamp('trial_ends_at')->nullable()->after('trial_started_at');
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('trial_ends_at');
            $table->timestamp('trial_ended_at')->nullable()->after('trial_reminder_sent_at');
            $table->index(['trial_ended_at', 'trial_ends_at']);
            $table->index('signed_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['trial_ended_at', 'trial_ends_at']);
            $table->dropIndex(['signed_up_at']);
            $table->dropColumn(['signed_up_at', 'trial_started_at', 'trial_ends_at', 'trial_reminder_sent_at', 'trial_ended_at']);
        });
    }
};
