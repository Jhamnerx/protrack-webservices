<?php

namespace App\Services\Formatters;

use App\Models\Config;
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
                'odometer' => $this->resolveOdometer($unit),
                'imei' => intval($unit['imei']),
                'idTrama' => $unit['hearttime'],
            ];
        }, $normalizedUnits);
    }

    /**
     * Odometro en km. Solo se reporta el valor de Protrack cuando es un entero positivo;
     * cualquier otra cosa (-1 cuando el equipo no lo soporta, decimales, null, texto)
     * se reporta como 0, porque PMGO rechaza la trama con 422 si no recibe un entero.
     */
    private function resolveOdometer(array $unit): int
    {
        $apiOdometer = $unit['odometer'] ?? $unit['mileage'] ?? null;

        if (!is_numeric($apiOdometer)) {
            return 0;
        }

        $apiOdometer = (float) $apiOdometer;

        if ($apiOdometer <= 0 || floor($apiOdometer) != $apiOdometer) {
            return 0;
        }

        return (int) $apiOdometer;
    }
}
