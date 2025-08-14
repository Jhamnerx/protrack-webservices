<?php

namespace App\Jobs;

use Carbon\Carbon;
use App\Models\Config;
use App\Models\Devices;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Api\ProtrackApiService;
use App\Services\Senders\OsinergminSender;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ReenviarHistorial implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $deviceImei;
    public $lastUpdate;
    public $endTime;
    public $timeout = 1200; // 20 minutos
    protected ProtrackApiService $protrackApiService;

    /**
     * Create a new job instance.
     */
    public function __construct($deviceImei, $lastUpdate, $endTime)
    {
        $this->deviceImei = $deviceImei;
        $this->lastUpdate = $lastUpdate;
        $this->endTime = $endTime;

        $this->onQueue('reenviar-historial');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->protrackApiService = app(ProtrackApiService::class);

        try {
            Log::info("Iniciando reenvío de historial para dispositivo {$this->deviceImei} desde {$this->lastUpdate} hasta {$this->endTime}");

            // Convertir fechas a timestamps para la API
            $beginTime = strtotime($this->lastUpdate);
            $endTime = strtotime($this->endTime);

            // Obtener datos del playback usando el nuevo servicio
            $playbackData = $this->protrackApiService->fetchPlayback($this->deviceImei, $beginTime, $endTime);
            if ($playbackData['total'] > 0) {
                Log::info("Obtenidos {$playbackData['total']} registros de historial para dispositivo {$this->deviceImei}");
                $this->reenviarHistorial($playbackData['records']);
            } else {
                Log::info("No se encontraron registros de historial para dispositivo {$this->deviceImei} en el período especificado");
            }
        } catch (\Exception $e) {
            Log::error("Error al reenviar historial para dispositivo {$this->deviceImei}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Procesa y envía el historial a Osinergmin
     */
    public function reenviarHistorial($posiciones)
    {
        if (empty($posiciones)) {
            Log::info("No hay posiciones para enviar para dispositivo {$this->deviceImei}");
            return;
        }

        $tramas = $this->format($posiciones);
        $url = "https://prod.osinergmin-agent-2021.com/api/v1/trama";

        $sender = new OsinergminSender();
        $sender->send($tramas, $url);

        Log::info("Enviadas " . count($tramas) . " tramas de historial para dispositivo {$this->deviceImei}");
    }

    /**
     * Formatea los datos del playback al formato requerido por Osinergmin
     */
    public function format(array $posiciones): array
    {
        $device = Devices::where('imei', $this->deviceImei)->first();

        if (!$device) {
            Log::error("Dispositivo con IMEI {$this->deviceImei} no encontrado en la base de datos");
            return [];
        }

        $config = Config::first();

        return array_map(function ($unit) use ($device, $config) {
            return [
                'id' => $device->id,
                'event' => 'none',
                'gpsDate' => Carbon::createFromTimestamp($unit['gpstime'])->utc()->format('Y-m-d\TH:i:s.v\Z'),
                'plate' => trim($device->plate),
                'speed' => intval($unit['speed']),
                'position' => [
                    'latitude' => doubleval($unit['latitude']),
                    'longitude' => doubleval($unit['longitude']),
                    'altitude' => doubleval(0),
                ],
                'tokenTrama' => $config->servicios['osinergmin']['token'],
                'odometer' => round(1),
                'imei' => $device->imei,
                'idTrama' => $device->imei . '_' . $unit['gpstime'], // ID único para cada trama
            ];
        }, $posiciones);
    }
}
