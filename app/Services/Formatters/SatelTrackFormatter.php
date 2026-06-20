<?php

namespace App\Services\Formatters;

use Carbon\Carbon;
use App\Services\Transformers\UnitTransformer;

/**
 * Formatea las posiciones al estándar de inserción de transmisiones de Tracklog (SatelTrack).
 *
 * Estructura de salida por item (campos documentados por Tracklog):
 *   placa, fechaEvento (Y-m-d), horaEvento (H:i:s), latitud, longitud,
 *   direccion (int), velocidad (int kph), evento (string), odometro (int km).
 *
 * Cada item incluye además metadatos (imei, idTrama, time_device, geo, event) que el
 * Sender usa para actualizar el dispositivo y registrar logs; el Sender los descarta
 * antes de armar el payload que viaja a Tracklog.
 */
class SatelTrackFormatter implements UnitFormatterInterface
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
            // $unit['fecha_hora'] viene en hora local de Perú (GMT-5) desde el Processor.
            $fechaHora = Carbon::parse($unit['fecha_hora']);

            return [
                // --- Payload Tracklog ---
                'placa' => trim($unit['plate']),
                'fechaEvento' => $fechaHora->format('Y-m-d'),
                'horaEvento' => $fechaHora->format('H:i:s'),
                'latitud' => (string) $unit['latitude'],
                'longitud' => (string) $unit['longitude'],
                'direccion' => intval($unit['course'] ?? 0),
                'velocidad' => intval($unit['speed']),
                'evento' => $this->resolveEvento($unit),
                'odometro' => intval($unit['odometer'] ?? 0),

                // --- Metadatos (no se envían a Tracklog) ---
                'imei' => intval($unit['imei']),
                'idTrama' => $unit['hearttime'],
                'time_device' => $unit['fecha_hora'],
                'geo' => [floatval($unit['latitude']), floatval($unit['longitude'])],
            ];
        }, $normalizedUnits);
    }

    /**
     * Determina el código de evento Tracklog a partir del estado del ACC (encendido).
     *
     *   accstatus = 1  -> ACC ON  -> "501" (Motor Encendido)
     *   accstatus = 0  -> ACC OFF -> "500" (Motor Apagado)
     *   accstatus = -1 / ausente  -> se infiere por velocidad:
     *        velocidad > 0 -> "501" (si se mueve, el motor está encendido)
     *        velocidad = 0 -> "2"   (Posición / reporte periódico)
     */
    private function resolveEvento(array $unit): string
    {
        $accstatus = isset($unit['accstatus']) ? (int) $unit['accstatus'] : -1;

        return match ($accstatus) {
            1 => '501',
            0 => '500',
            default => intval($unit['speed'] ?? 0) > 0 ? '501' : '2',
        };
    }
}

