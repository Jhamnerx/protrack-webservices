<?php

namespace App\Services\Formatters;

use App\Models\Config;
use App\Models\Devices;
use App\Services\Transformers\UnitTransformer;


class OsinergminFormatter implements UnitFormatterInterface
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

            $device = Devices::where('imei', $unit['imei'])->first();

            $config = Config::first();

            return [
                'id' => $unit['id'],
                'event' => 'none',
                'gpsDate' => gmdate('Y-m-d\TH:i:s.v\Z', $unit['hearttime']),
                'plate' => trim($unit['plate']),
                'speed' => intval($unit['speed']),
                'position' => [
                    'latitude' => doubleval($unit['latitude']),
                    'longitude' => doubleval($unit['longitude']),
                    'altitude' => doubleval(0),
                ],
                'tokenTrama' => $config->servicios['osinergmin']['token'],
                'odometer' => round($unit['odometer'] ?? 0, 2),
                'imei' => intval($unit['imei']),
                'idTrama' => $unit['hearttime'],
            ];
        }, $normalizedUnits);
    }
}
