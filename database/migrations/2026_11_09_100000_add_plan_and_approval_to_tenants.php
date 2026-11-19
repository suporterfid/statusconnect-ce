<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plans (#119) and sign-up approval (#120).
 *
 * Organizations that exist before this migration keep working as they did:
 * they stay on the internal `unlimited` plan (the column default, so self-hosted
 * installs have no limits) and count as approved, and their
 * users count as having a verified email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan', 32)->default('unlimited')->after('slug');
            $table->timestamp('approved_at')->nullable()->after('plan');
        });

        DB::table('tenants')->update(['plan' => 'unlimited', 'approved_at' => DB::raw('created_at')]);
        DB::table('tenants')->whereNull('approved_at')->update(['approved_at' => now()]);
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['plan', 'approved_at']);
        });
    }
};
