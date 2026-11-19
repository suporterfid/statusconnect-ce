<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for `agent` monitors (agent-ingestion addendum §6): only the latest
 * value per metric, never a raw time series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->char('agent_token_hash', 64)->nullable()->unique()->after('heartbeat_grace_seconds');
            $table->unsignedInteger('agent_grace_seconds')->default(180)->after('agent_token_hash');
            $table->timestamp('last_ingest_at')->nullable()->after('agent_grace_seconds');
        });

        Schema::create('agent_metric_latest', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->char('metric_key_hash', 64);
            $table->string('metric_key', 255);
            $table->double('value');
            $table->timestamp('sampled_at');
            $table->timestamp('received_at');

            $table->unique(['monitor_id', 'metric_key_hash']);
            $table->index('received_at');
        });

        Schema::create('agent_metric_rules', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->string('metric_key', 255);
            $table->string('operator', 8);
            $table->string('expected', 64);
            $table->string('severity', 8)->default('down');
            $table->string('missing_policy', 8)->default('ignore');
            $table->timestamps();

            $table->index('monitor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_metric_rules');
        Schema::dropIfExists('agent_metric_latest');
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropUnique(['agent_token_hash']);
            $table->dropColumn(['agent_token_hash', 'agent_grace_seconds', 'last_ingest_at']);
        });
    }
};
