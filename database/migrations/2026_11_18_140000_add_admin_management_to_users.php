<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform administration of users and organizations (#248).
 *
 * users.blocked_at       a platform admin blocked the user: no sign-in by any method.
 * users.last_login_at    last successful sign-in, by any method.
 * users.last_login_method password, google or grandpasson.
 * users.sign_in_methods  every method the user has signed in with (JSON list).
 * users.session_version  bumped to end every session; sessions remember the version they started with.
 *
 * status_page_slug_redirects keeps old status page addresses answering 301 to the current one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('is_platform_admin');
            $table->timestamp('last_login_at')->nullable()->after('blocked_at');
            $table->string('last_login_method', 16)->nullable()->after('last_login_at');
            $table->json('sign_in_methods')->nullable()->after('last_login_method');
            $table->unsignedInteger('session_version')->default(0)->after('sign_in_methods');
        });

        Schema::create('status_page_slug_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('old_slug', 120)->unique();
            $table->foreignId('status_page_id')->constrained('status_pages')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_page_slug_redirects');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'last_login_at', 'last_login_method', 'sign_in_methods', 'session_version']);
        });
    }
};
