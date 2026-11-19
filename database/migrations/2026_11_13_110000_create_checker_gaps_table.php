<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Periods in which the checker did not run (#199): cron stopped, the server
 * was down or the installation was being moved. Nothing was checked, so the
 * status page shows them as "no data".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checker_gaps', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->timestamps();

            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checker_gaps');
    }
};
