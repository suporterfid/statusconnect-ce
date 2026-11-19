<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('environment_id')->constrained('environments')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('suppress_notifications')->default(true);
            $table->boolean('recurring_weekly')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'environment_id', 'starts_at', 'ends_at'], 'maintenance_windows_scope_range_index');
        });

        Schema::create('maintenance_window_monitors', function (Blueprint $table) {
            $table->foreignId('maintenance_window_id')->constrained('maintenance_windows')->cascadeOnDelete();
            $table->foreignId('monitor_id')->constrained('monitors')->cascadeOnDelete();
            $table->primary(['maintenance_window_id', 'monitor_id'], 'mwm_window_monitor_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_window_monitors');
        Schema::dropIfExists('maintenance_windows');
    }
};
