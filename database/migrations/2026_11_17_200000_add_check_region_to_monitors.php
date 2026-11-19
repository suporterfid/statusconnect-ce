<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probe region per monitor (#222): which remote probe region runs its HTTP
 * checks when CHECK_EXECUTION=probe. Null is the default region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('check_region', 32)->nullable()->after('egress_profile');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn('check_region');
        });
    }
};
