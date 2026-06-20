<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda el último timestamp (unix) hasta el que ya se procesaron alarmas
     * de SatelTrack por dispositivo, para no reenviar alarmas duplicadas.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedInteger('last_alarm_check')->nullable()->after('latest_position_id');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('last_alarm_check');
        });
    }
};
