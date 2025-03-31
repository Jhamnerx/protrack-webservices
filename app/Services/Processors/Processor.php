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
                $unit['fecha_hora'] = $deviceTime->format('Y-m-d H:i:s');
                $deviceLastUpdate = Carbon::parse($device->last_update);

                if ($deviceTime->format('Y-m-d H:i:s') != $deviceLastUpdate->format('Y-m-d H:i:s')) {

                    if ($device->services['sutran']['active'] ?? false) {

                        if ($unit['datastatus'] == "2") {

                            $result['sutran'][] = $unit;
                        }
                    }
                }
            }
        }
        return $result;
    }
}
