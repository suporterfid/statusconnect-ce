<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a check ran (#222): null for this server, or the remote probe's
 * location label (for example "São Paulo, BR") when CHECK_EXECUTION=probe.
 * Added last, without ->after(), so MySQL can add it instantly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->string('location', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('check_results', function (Blueprint $table) {
            $table->dropColumn('location');
        });
    }
};
