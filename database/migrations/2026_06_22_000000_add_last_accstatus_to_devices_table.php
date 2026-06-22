<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Guarda el último estado conocido del ACC (encendido) por dispositivo:
     * 1 = ON, 0 = OFF, null = aún desconocido. Se usa para detectar la transición
     * de encendido/apagado y emitir los eventos Tracklog 501/500 solo en el cambio.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->tinyInteger('last_accstatus')->nullable()->after('last_alarm_check');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('last_accstatus');
        });
    }
};
