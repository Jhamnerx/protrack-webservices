<?php

namespace App\Services\Formatters;

use DateTime;
use DateTimeZone;
use App\Models\Devices;
use App\Models\WialonDevices;
use Illuminate\Support\Facades\Log;
use App\Services\Transformers\UnitTransformer;


class SutranFormatter implements UnitFormatterInterface
{
    protected $transformer;

    public function __construct(UnitTransformer $transformer)
    {
        $this->transformer = $transformer;
    }

    public function format(array $units): array
    {

        $normalizedUnits = $this->transformer->transform($units);

        return array_map(function ($unit) {
            return [
                'id' => $unit['id'],
                'plate' => trim(str_replace('-', '', $unit['plate'])),
                'geo' => [floatval($unit['latitude']), floatval($unit['longitude'])],
                'direction' => intval($unit['course'] ?? 0),
                'event' => $unit['speed'] > 5 ? 'ER' : 'PA',
                'speed' => intval($unit['speed']),
                'time_device' => $unit['fecha_hora'],
                'imei' => intval($unit['imei']),
                'idTrama' => $unit['hearttime'],
            ];
        }, $normalizedUnits);
    }
}
