<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->string('logo_path', 255)->nullable()->after('timezone');
            $table->string('accent_color', 7)->nullable()->after('logo_path');
            $table->string('website_url', 255)->nullable()->after('accent_color');
            $table->string('privacy_url', 255)->nullable()->after('website_url');
            $table->boolean('show_powered_by')->default(true)->after('privacy_url');
        });
    }

    public function down(): void
    {
        Schema::table('status_pages', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'accent_color', 'website_url', 'privacy_url', 'show_powered_by']);
        });
    }
};
