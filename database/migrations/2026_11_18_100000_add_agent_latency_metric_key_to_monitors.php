<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latency metric of an agent monitor (#242): the key of a pushed metric whose
 * value, in ms, becomes the latency of each agent check. Null = no latency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('agent_latency_metric_key', 255)->nullable()->after('agent_grace_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('agent_latency_metric_key');
        });
    }
};
