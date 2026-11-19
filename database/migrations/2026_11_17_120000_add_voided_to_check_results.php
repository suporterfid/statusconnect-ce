<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Voided check results (#233): a failure the checker measured wrongly stays
 * as evidence but no longer counts toward availability, downtime or latency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('location');
            $table->string('void_reason', 255)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
