<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-written public announcements shown on a status page. Kept apart from
 * incident_updates on purpose: incident update text can carry internal target
 * details, so only text written here, through an explicit publish action, is public.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_page_updates', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->foreignId('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16);
            $table->string('impact', 16)->default('none');
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->index(['status_page_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_updates');
    }
};
