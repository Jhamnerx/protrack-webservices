<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Odómetro virtual: la API de Protrack devuelve odometer=-1 (no soportado),
     * así que acumulamos la distancia recorrida (en metros) entre puntos consecutivos.
     * Se puede sembrar con el kilometraje real inicial del vehículo (en metros).
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->double('odometer_meters')->default(0)->after('last_accstatus');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('odometer_meters');
        });
    }
};
