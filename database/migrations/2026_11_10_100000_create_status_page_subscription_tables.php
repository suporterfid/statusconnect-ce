<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email subscriptions to public status pages (#92).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->boolean('subscriptions_enabled')->default(false);
            // Events newer than this are turned into notifications by the minute cron.
            $table->timestamp('subscriptions_checked_at')->nullable();
        });

        Schema::create('status_page_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->string('email', 255);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['status_page_id', 'email']);
            $table->index(['status_page_id', 'confirmed_at']);
        });

        Schema::create('status_page_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            // incident_opened:{id} | incident_resolved:{id} | update:{id}
            $table->string('reference', 64);
            $table->string('kind', 32);
            $table->longText('payload');
            // Subscribers are sent to in id order; the cursor is the last one done.
            $table->unsignedBigInteger('cursor_subscriber_id')->default(0);
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['status_page_id', 'reference']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_notifications');
        Schema::dropIfExists('status_page_subscribers');
        Schema::table('status_pages', function (Blueprint $table) {
            $table->dropColumn(['subscriptions_enabled', 'subscriptions_checked_at']);
        });
    }
};
