<?php

namespace App\Services\Processors;

use Carbon\Carbon;
use App\Models\Devices;
use App\Jobs\ReenviarHistorial;
use Illuminate\Support\Facades\Log;


class Processor implements UnitProcessorInterface
{
    public function processUnits($units): array
    {

        $result = [
            'sutran' => [],
            'osinergmin' => [],
        ];

        foreach ($units  as $key => $unit) {

            $device = Devices::where('imei', $unit['imei'])->first();
            $unit['id'] = $device->id ?? null;
            $unit['plate'] = $device->plate ?? null;
            $unit['type'] = $device->type ?? null;
            $unit['name'] = $device->name ?? null;
            $unit['imei'] = $device->imei ?? null;

            if ($device) {

                $deviceTime = Carbon::parse($unit['gpstime'])->setTimezone('America/Lima');

                $deviceLastUpdate = Carbon::parse($device->last_update);
                $ultimo_envio_date = Carbon::parse($unit['hearttime'])->setTimezone('America/Lima');
                $unit['fecha_hora'] = $ultimo_envio_date->format('Y-m-d H:i:s');
                //VERIFICAR ULTIMA FECHA Y HORA ENVIADA PARA NO ENVIAR INFORMACION REPETIDA
                Log::info('fecha actualizacion db: ' . $deviceLastUpdate->format('Y-m-d H:i:s') . ' fecha dispositivo: ' . $deviceTime->format('Y-m-d H:i:s') . ' fecha ultimo envio: ' . $ultimo_envio_date->format('Y-m-d H:i:s'));
                if ($ultimo_envio_date->format('Y-m-d H:i:s') != $deviceLastUpdate->format('Y-m-d H:i:s')) {

                    if ($device->services['sutran']['active'] ?? false) {

                        if ($unit['datastatus'] == "2" || $unit['datastatus'] == "4") {

                            $result['sutran'][] = $unit;
                        }
                    }

                    if ($device->services['osinergmin']['active'] ?? false) {

                        if ($unit['datastatus'] == "2" || $unit['datastatus'] == "4") {

                            $result['osinergmin'][] = $unit;
                        }
                    }

                    if ($unit['datastatus'] == "4") {

                        $unit['fecha_hora'] = $ultimo_envio_date->format('Y-m-d H:i:s');
                    }
                }
            }
        }
        return $result;
    }
}
