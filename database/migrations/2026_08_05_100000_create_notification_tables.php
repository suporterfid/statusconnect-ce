<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->text('config_json');
            $table->boolean('enabled')->default(true);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['environment_id', 'enabled']);
        });

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 36)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('incidents')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->string('event', 32);
            $table->string('status', 32)->default('queued');
            $table->integer('response_code')->nullable();
            $table->text('error_excerpt')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'channel_id', 'event']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_channels');
    }
};
