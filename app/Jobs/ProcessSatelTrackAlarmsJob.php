<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Config;
use App\Models\Devices;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Api\ProtrackApiService;
use App\Services\Senders\SatelTrackSender;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Consulta las alarmas de conducción de Protrack (/api/alarm/list) para cada
 * dispositivo con SatelTrack activo y reenvía las de aceleración/frenado/giro
 * brusco al WS de Tracklog con su código de evento correspondiente.
 *
 * Mapeo Protrack alarmType -> evento Tracklog:
 *   23 (rapidAcceleration) -> 135 (Aceleración Brusca)
 *   24 (rapidDeceleration) -> 134 (Frenado Brusco)
 *   25 (sharpTurn)         -> 143 (Giro Peligroso)
 */
class ProcessSatelTrackAlarmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    /** Códigos de alarma Protrack que interesan, mapeados al evento Tracklog. */
    private const ALARM_EVENT_MAP = [
        23 => '135', // Aceleración Brusca
        24 => '134', // Frenado Brusco
        25 => '143', // Giro Peligroso
    ];

    /** Ventana por defecto (segundos) hacia atrás cuando un dispositivo no tiene last_alarm_check. */
    private const DEFAULT_LOOKBACK = 120;

    public function __construct()
    {
        $this->onQueue('web-services-sateltrack');
    }

    public function handle(): void
    {
        $config = Config::first();

        if (!($config->servicios['sateltrack']['status'] ?? false)) {
            return;
        }

        $url = $config->servicios['sateltrack']['url'] ?? null;

        if (empty($url)) {
            Log::channel('sateltrack')->warning('Alarmas: URL de SatelTrack no configurada; se omite el ciclo');
            return;
        }

        $devices = Devices::where('services->sateltrack->active', true)->get();

        if ($devices->isEmpty()) {
            return;
        }

        $protrackService = app(ProtrackApiService::class);
        $now = time();

        foreach ($devices as $device) {
            $this->processDevice($device, $protrackService, $url, $now);
        }
    }

    private function processDevice(Devices $device, ProtrackApiService $protrackService, string $url, int $now): void
    {
        $beginTime = $device->last_alarm_check ?? ($now - self::DEFAULT_LOOKBACK);

        // Evitar rangos inválidos.
        if ($beginTime >= $now) {
            $beginTime = $now - self::DEFAULT_LOOKBACK;
        }

        try {
            $alarmData = $protrackService->fetchAlarms($device->imei, $beginTime, $now);

            if ($alarmData['total'] > 0) {
                $tramas = $this->formatAlarms($device, $alarmData['records']);

                if (!empty($tramas)) {
                    Log::channel('sateltrack')->info('Alarmas a enviar', [
                        'imei' => $device->imei,
                        'plate' => $device->plate,
                        'total_alarmas' => $alarmData['total'],
                        'tramas_relevantes' => count($tramas),
                    ]);

                    foreach (array_chunk($tramas, 200) as $grupo) {
                        // updateDevices: false -> las alarmas no deben pisar la posición del flujo principal.
                        $sender = new SatelTrackSender(updateDevices: false);
                        $sender->send($grupo, $url);
                    }
                }
            }

            // Avanzar el cursor aunque no haya alarmas, para no reconsultar el mismo rango.
            $device->update(['last_alarm_check' => $now]);
        } catch (\Exception $e) {
            Log::channel('sateltrack')->error('Error al procesar alarmas del dispositivo', [
                'imei' => $device->imei,
                'plate' => $device->plate,
                'error' => $e->getMessage(),
            ]);
            // No se avanza el cursor: se reintentará el mismo rango en el próximo ciclo.
        }
    }

    /**
     * Convierte los registros de alarma relevantes (23/24/25) a tramas Tracklog.
     */
    private function formatAlarms(Devices $device, array $records): array
    {
        $tramas = [];

        foreach ($records as $rec) {
            $evento = self::ALARM_EVENT_MAP[$rec['alarmType']] ?? null;

            if ($evento === null) {
                continue; // Tipo de alarma no relevante para Tracklog.
            }

            $dt = Carbon::createFromTimestamp($rec['gpstime'])->setTimezone('America/Lima');

            $tramas[] = [
                // --- Payload Tracklog ---
                'placa' => trim($device->plate),
                'fechaEvento' => $dt->format('Y-m-d'),
                'horaEvento' => $dt->format('H:i:s'),
                'latitud' => (string) $rec['latitude'],
                'longitud' => (string) $rec['longitude'],
                'direccion' => intval($rec['course'] ?? 0),
                'velocidad' => intval($rec['speed']),
                'evento' => $evento,
                'odometro' => 0,

                // --- Metadatos ---
                'imei' => intval($device->imei),
                'idTrama' => $device->imei . '_' . $rec['gpstime'] . '_' . $rec['alarmType'],
                'time_device' => $dt->format('Y-m-d H:i:s'),
                'geo' => [floatval($rec['latitude']), floatval($rec['longitude'])],
            ];
        }

        return $tramas;
    }
}
