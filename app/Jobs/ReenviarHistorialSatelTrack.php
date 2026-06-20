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
 * Reenvía el historial (playback) de un dispositivo a SatelTrack (Tracklog)
 * para un rango de fechas. Se dispara manualmente desde la UI.
 */
class ReenviarHistorialSatelTrack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deviceImei;
    public $lastUpdate;
    public $endTime;
    public $timeout = 1200; // 20 minutos
    protected ProtrackApiService $protrackApiService;

    public function __construct($deviceImei, $lastUpdate, $endTime)
    {
        $this->deviceImei = $deviceImei;
        $this->lastUpdate = $lastUpdate;
        $this->endTime = $endTime;

        $this->onQueue('reenviar-historial');
    }

    public function handle(): void
    {
        $this->protrackApiService = app(ProtrackApiService::class);

        try {
            Log::channel('sateltrack')->info("Iniciando reenvío de historial para dispositivo {$this->deviceImei} desde {$this->lastUpdate} hasta {$this->endTime}");

            $beginTime = strtotime($this->lastUpdate);
            $endTime = strtotime($this->endTime);

            $playbackData = $this->protrackApiService->fetchPlayback($this->deviceImei, $beginTime, $endTime);

            if ($playbackData['total'] > 0) {
                Log::channel('sateltrack')->info("Obtenidos {$playbackData['total']} registros de historial para dispositivo {$this->deviceImei}");
                $this->reenviarHistorial($playbackData['records']);
            } else {
                Log::channel('sateltrack')->info("No se encontraron registros de historial para dispositivo {$this->deviceImei} en el período especificado");
            }
        } catch (\Exception $e) {
            Log::channel('sateltrack')->error("Error al reenviar historial a SatelTrack para dispositivo {$this->deviceImei}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    public function reenviarHistorial(array $posiciones): void
    {
        if (empty($posiciones)) {
            Log::channel('sateltrack')->info("No hay posiciones para enviar para dispositivo {$this->deviceImei}");
            return;
        }

        $tramas = $this->format($posiciones);

        if (empty($tramas)) {
            return;
        }

        $url = Config::first()->servicios['sateltrack']['url'] ?? null;

        if (empty($url)) {
            Log::channel('sateltrack')->error("URL de SatelTrack no configurada; no se reenvía historial del dispositivo {$this->deviceImei}");
            return;
        }

        // Tracklog acepta un máximo de 200 registros por envío.
        foreach (array_chunk($tramas, 200) as $grupo) {
            $sender = new SatelTrackSender();
            $sender->send($grupo, $url);
        }

        Log::channel('sateltrack')->info("Reenviadas " . count($tramas) . " tramas de historial para dispositivo {$this->deviceImei} a SatelTrack");
    }

    /**
     * Formatea los registros de playback al estándar de Tracklog (SatelTrack).
     */
    public function format(array $posiciones): array
    {
        $device = Devices::where('imei', $this->deviceImei)->first();

        if (!$device) {
            Log::channel('sateltrack')->error("Dispositivo con IMEI {$this->deviceImei} no encontrado en la base de datos");
            return [];
        }

        return array_map(function ($rec) use ($device) {
            $dt = Carbon::createFromTimestamp($rec['gpstime'])->setTimezone('America/Lima');

            return [
                // --- Payload Tracklog ---
                'placa' => trim($device->plate),
                'fechaEvento' => $dt->format('Y-m-d'),
                'horaEvento' => $dt->format('H:i:s'),
                'latitud' => (string) $rec['latitude'],
                'longitud' => (string) $rec['longitude'],
                'direccion' => intval($rec['course'] ?? 0),
                'velocidad' => intval($rec['speed']),
                'evento' => '2', // 2 = Posición
                'odometro' => 0,

                // --- Metadatos ---
                'imei' => intval($device->imei),
                'idTrama' => $device->imei . '_' . $rec['gpstime'],
                'time_device' => $dt->format('Y-m-d H:i:s'),
                'geo' => [floatval($rec['latitude']), floatval($rec['longitude'])],
            ];
        }, $posiciones);
    }
}
