<?php

namespace App\Services\Formatters;

use Carbon\Carbon;
use App\Models\Devices;
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
    /** Distancia mínima (m) para acumular: descarta el jitter del GPS estando detenido. */
    private const MIN_DELTA_M = 20;

    /** Distancia máxima (m) para acumular: descarta saltos imposibles (glitch/teletransporte). */
    private const MAX_DELTA_M = 50000;

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

            $accstatus = isset($unit['accstatus']) ? (int) $unit['accstatus'] : -1;

            // Estado previo del dispositivo: ACC (para transición motor on/off),
            // último punto (para el odómetro virtual) y odómetro acumulado.
            $device = Devices::where('imei', $unit['imei'])->first();
            $prevAccstatus = $device?->last_accstatus;

            [$odometroKm, $odometerMeters] = $this->resolveOdometro($unit, $device);

            return [
                // --- Payload Tracklog ---
                'placa' => trim($unit['plate']),
                'fechaEvento' => $fechaHora->format('Y-m-d'),
                'horaEvento' => $fechaHora->format('H:i:s'),
                'latitud' => (string) $unit['latitude'],
                'longitud' => (string) $unit['longitude'],
                'direccion' => intval($unit['course'] ?? 0),
                'velocidad' => intval($unit['speed']),
                'evento' => $this->resolveEvento($accstatus, $prevAccstatus),
                'odometro' => $odometroKm,

                // --- Metadatos (no se envían a Tracklog) ---
                'imei' => intval($unit['imei']),
                'idTrama' => $unit['hearttime'],
                'time_device' => $unit['fecha_hora'],
                'geo' => [floatval($unit['latitude']), floatval($unit['longitude'])],
                'accstatus' => $accstatus,        // el Sender lo persiste para la próxima transición
                'odometer_meters' => $odometerMeters, // el Sender lo persiste como odómetro acumulado
            ];
        }, $normalizedUnits);
    }

    /**
     * Calcula el odómetro a reportar (en km) y el acumulado actualizado (en metros).
     *
     * Como Protrack devuelve odometer=-1, se construye un odómetro virtual acumulando
     * la distancia (haversine) entre el último punto enviado y el actual. Si la API
     * llegara a devolver un odómetro real (>0) se usa ese valor.
     *
     * @return array{0:int,1:float} [odometroKm, odometerMetersAcumulado]
     */
    private function resolveOdometro(array $unit, ?Devices $device): array
    {
        $apiOdometer = intval($unit['odometer'] ?? -1);
        $acumulado = (float) ($device?->odometer_meters ?? 0);

        if ($apiOdometer > 0) {
            return [$apiOdometer, $acumulado];
        }

        // Solo acumular si el vehículo está realmente en movimiento: motor encendido
        // (accstatus = 1; o desconocido = -1) y con velocidad > 0. Con el motor apagado
        // (accstatus = 0) no se suma, aunque el GPS reporte velocidad por ruido.
        $speed = intval($unit['speed'] ?? 0);
        $accstatus = isset($unit['accstatus']) ? (int) $unit['accstatus'] : -1;
        $enMovimiento = $speed > 0 && $accstatus !== 0;

        $prev = $device?->last_position;

        if ($enMovimiento && is_array($prev) && count($prev) === 2 && is_numeric($prev[0]) && is_numeric($prev[1])) {
            $delta = $this->haversine(
                (float) $prev[0],
                (float) $prev[1],
                (float) $unit['latitude'],
                (float) $unit['longitude']
            );

            // Solo acumular desplazamientos plausibles (ni jitter ni saltos imposibles).
            if ($delta >= self::MIN_DELTA_M && $delta <= self::MAX_DELTA_M) {
                $acumulado += $delta;
            }
        }

        return [(int) round($acumulado / 1000), $acumulado];
    }

    /**
     * Distancia en metros entre dos coordenadas (fórmula del haversine).
     */
    private function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // metros

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Determina el código de evento Tracklog. Los eventos de motor (500/501) solo se
     * emiten en la TRANSICIÓN del ACC; el reporte periódico normal es "2" (Posición).
     *
     *   ACC pasa a ON  (acc=1, anterior != 1)          -> "501" (Motor Encendido)
     *   ACC pasa a OFF (acc=0, anterior = 1)            -> "500" (Motor Apagado)
     *   sin cambio / acc desconocido (-1) / sin estado  -> "2"   (Posición)
     *
     * @param int      $accstatus     Estado actual del ACC (1, 0 o -1).
     * @param int|null $prevAccstatus Último estado conocido del dispositivo (null si nunca).
     */
    private function resolveEvento(int $accstatus, ?int $prevAccstatus): string
    {
        if ($accstatus === 1 && $prevAccstatus !== 1) {
            return '501'; // Motor Encendido (se acaba de detectar ignición)
        }

        if ($accstatus === 0 && $prevAccstatus === 1) {
            return '500'; // Motor Apagado (transición desde encendido)
        }

        return '2'; // Posición (reporte periódico normal)
    }
}

