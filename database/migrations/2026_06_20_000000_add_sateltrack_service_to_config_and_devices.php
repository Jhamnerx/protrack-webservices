<?php

use App\Models\Config;
use App\Models\Devices;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Agrega el servicio "sateltrack" a la configuración global y al JSON
     * de servicios de cada dispositivo existente.
     */
    public function up(): void
    {
        // Configuración global: estructura del servicio SatelTrack (Tracklog).
        Config::all()->each(function (Config $config) {
            $servicios = $config->servicios ?? [];

            if (!isset($servicios['sateltrack'])) {
                $servicios['sateltrack'] = [
                    'url' => '',
                    'status' => 0,
                    'enabled_logs' => 0,
                ];

                $config->update(['servicios' => $servicios]);
            }
        });

        // Dispositivos: agregar 'sateltrack' => ['active' => false] a cada uno.
        Devices::all()->each(function (Devices $device) {
            $services = $device->services ?? [];

            if (!isset($services['sateltrack'])) {
                $services['sateltrack'] = ['active' => false];
                $device->update(['services' => $services]);
            }
        });
    }

    /**
     * Elimina el servicio "sateltrack" de la configuración y de los dispositivos.
     */
    public function down(): void
    {
        Config::all()->each(function (Config $config) {
            $servicios = $config->servicios ?? [];

            if (isset($servicios['sateltrack'])) {
                unset($servicios['sateltrack']);
                $config->update(['servicios' => $servicios]);
            }
        });

        Devices::all()->each(function (Devices $device) {
            $services = $device->services ?? [];

            if (isset($services['sateltrack'])) {
                unset($services['sateltrack']);
                $device->update(['services' => $services]);
            }
        });
    }
};
