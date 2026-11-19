<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MQTT monitors (#200): CONNECT/CONNACK against a broker. The password is
 * stored encrypted with APP_KEY and never returned by the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->string('mqtt_client_id', 64)->nullable()->after('agent_grace_seconds');
            $table->string('mqtt_username', 255)->nullable()->after('mqtt_client_id');
            $table->text('mqtt_password')->nullable()->after('mqtt_username');
        });
    }

    public function down(): void
    {
        Schema::table('monitors', function (Blueprint $table) {
            $table->dropColumn(['mqtt_client_id', 'mqtt_username', 'mqtt_password']);
        });
    }
};
