<?php

namespace App\Services\Senders;

use App\Models\Config;
use App\Models\Devices;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;

/**
 * Envía posiciones al servicio web de inserción de transmisiones de Tracklog (SatelTrack).
 *
 * - Método POST, body JSON con el bloque "items".
 * - La URL es configurable por EMV/Transportista (config->servicios['sateltrack']['url']).
 * - Respuesta esperada: { "Recibidos": [ { "Placa": ..., "Fecha_Hora": "Y-m-d H:i:s" }, ... ] }
 */
class SatelTrackSender implements UnitSenderInterface
{
    public $logService;
    public $config;

    /**
     * Si es false, no se actualiza el dispositivo (last_update, etc.) tras un envío exitoso.
     * Se usa para alarmas, que son eventos discretos y no deben pisar la posición del flujo principal.
     */
    protected bool $updateDevices;

    /** Campos que viajan dentro de cada item a Tracklog (el resto son metadatos). */
    protected array $wireKeys = [
        'placa',
        'fechaEvento',
        'horaEvento',
        'latitud',
        'longitud',
        'direccion',
        'velocidad',
        'evento',
        'odometro',
    ];

    public function __construct(bool $updateDevices = true)
    {
        $this->updateDevices = $updateDevices;
        $this->logService = app(LogService::class);
        $this->config = Config::first();
    }

    public function send(array $tramas, $url): void
    {
        if (empty($tramas)) {
            Log::channel('sateltrack')->info('No hay tramas para enviar a SatelTrack');
            return;
        }

        if (empty($url)) {
            Log::channel('sateltrack')->error('No se configuró la URL de SatelTrack; envío abortado', [
                'total_tramas' => count($tramas),
            ]);
            return;
        }

        // Evitar registros repetidos: una misma placa no debe enviarse dos veces
        // con la misma fecha/hora de evento dentro del mismo lote.
        $tramas = $this->dedupeTramas($tramas);

        Log::channel('sateltrack')->info('Iniciando envío de batch', [
            'total_tramas' => count($tramas),
            'url' => $url,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Construir el payload solo con los campos documentados.
        $items = array_map(function ($trama) {
            return array_intersect_key($trama, array_flip($this->wireKeys));
        }, $tramas);

        try {
            $client = new Client(['verify' => false, 'connect_timeout' => 5, 'timeout' => 30]);
            $response = $client->request('POST', $url, [
                'headers' => ['Content-Type' => 'application/json'],
                'json' => ['items' => array_values($items)],
            ]);

            $responseBody = $response->getBody()->getContents();
            $responseData = json_decode($responseBody, true);

            Log::channel('sateltrack')->info('Respuesta recibida de SatelTrack', [
                'response' => $responseData,
            ]);

            $this->actionAfterSend($tramas, $responseData);
        } catch (RequestException $e) {
            Log::channel('sateltrack')->error('Error en conexión con SatelTrack', [
                'error' => $e->getMessage(),
                'tramas_count' => count($tramas),
            ]);

            $errorBody = null;
            if ($e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                Log::channel('sateltrack')->error('Respuesta de error de SatelTrack', [
                    'response_body' => $errorBody,
                    'status_code' => $e->getResponse()->getStatusCode(),
                ]);
            }

            if ($this->config->servicios['sateltrack']['enabled_logs'] ?? false) {
                $this->logService->logToDatabase(
                    '',
                    'Tracklog',
                    'N/A',
                    'error',
                    $items,
                    ['message' => $errorBody ?? 'Error de conexión: ' . $e->getMessage()],
                    [],
                    null,
                    null
                );
            }

            $this->updateCounterService(0, count($tramas), count($tramas), 'Error de conexión con SatelTrack');
        } catch (\Exception $e) {
            Log::channel('sateltrack')->error('Error inesperado al enviar a SatelTrack', [
                'error' => $e->getMessage(),
            ]);
            $this->updateCounterService(0, count($tramas), count($tramas), $e->getMessage());
        }
    }

    /**
     * Procesa la respuesta de Tracklog y marca cada trama como éxito/error.
     */
    protected function actionAfterSend(array $tramas, $response): void
    {
        $totalSent = count($tramas);
        $successCount = 0;
        $errorCount = 0;

        // Construir conjunto de tramas recibidas: "PLACA|Y-m-d H:i:s"
        $recibidos = [];
        foreach ($response['Recibidos'] ?? [] as $item) {
            $placa = trim($item['Placa'] ?? '');
            $fechaHora = trim($item['Fecha_Hora'] ?? '');
            if ($placa !== '' && $fechaHora !== '') {
                $recibidos[strtoupper($placa) . '|' . $fechaHora] = true;
            }
        }

        $enabledLogs = $this->config->servicios['sateltrack']['enabled_logs'] ?? false;

        foreach ($tramas as $trama) {
            $clave = strtoupper(trim($trama['placa'])) . '|' . $trama['fechaEvento'] . ' ' . $trama['horaEvento'];
            $fueRecibida = isset($recibidos[$clave]);

            if ($fueRecibida) {
                $successCount++;

                if ($enabledLogs) {
                    $this->logService->logToDatabase(
                        '',
                        'Tracklog',
                        $trama['placa'],
                        'success',
                        $this->wireTrama($trama),
                        ['message' => 'Registrado correctamente'],
                        [],
                        $trama['time_device'],
                        $trama['imei']
                    );
                }

                $this->updateDevice($trama);
            } else {
                $errorCount++;

                Log::channel('sateltrack')->warning('Trama no confirmada por SatelTrack', [
                    'placa' => $trama['placa'] ?? 'N/A',
                    'imei' => $trama['imei'] ?? 'N/A',
                    'fecha_hora' => $trama['fechaEvento'] . ' ' . $trama['horaEvento'],
                ]);

                if ($enabledLogs) {
                    $this->logService->logToDatabase(
                        '',
                        'Tracklog',
                        $trama['placa'],
                        'error',
                        $this->wireTrama($trama),
                        ['message' => 'No confirmada en la respuesta (Recibidos)'],
                        [],
                        $trama['time_device'],
                        $trama['imei']
                    );
                }
            }
        }

        Log::channel('sateltrack')->info('Batch completado', [
            'total_sent' => $totalSent,
            'success_count' => $successCount,
            'failed_count' => $errorCount,
            'success_rate' => $totalSent > 0 ? round(($successCount / $totalSent) * 100, 2) . '%' : '0%',
            'timestamp' => now()->toDateTimeString(),
        ]);

        $this->updateCounterService(
            $successCount,
            $errorCount,
            $totalSent,
            $errorCount > 0 ? 'Errores en algunas tramas' : null
        );
    }

    /**
     * Devuelve solo los campos documentados (sin metadatos) para logging.
     */
    protected function wireTrama(array $trama): array
    {
        return array_intersect_key($trama, array_flip($this->wireKeys));
    }

    /**
     * Elimina tramas duplicadas dentro del lote usando placa + fecha + hora de evento
     * como clave. Conserva la primera ocurrencia.
     */
    protected function dedupeTramas(array $tramas): array
    {
        $unicas = [];

        foreach ($tramas as $trama) {
            $clave = strtoupper(trim($trama['placa'] ?? '')) . '|'
                . ($trama['fechaEvento'] ?? '') . '|'
                . ($trama['horaEvento'] ?? '');

            if (!isset($unicas[$clave])) {
                $unicas[$clave] = $trama;
            }
        }

        return array_values($unicas);
    }

    protected function updateDevice(array $trama): void
    {
        if (!$this->updateDevices) {
            return;
        }

        try {
            $device = Devices::where('imei', $trama['imei'])->first();

            if ($device) {
                $payload = [
                    'last_status' => $trama['evento'],
                    'last_position' => $trama['geo'],
                    'last_update' => $trama['time_device'],
                    'latest_position_id' => $trama['idTrama'],
                ];

                // Avanzar el estado de ACC solo cuando se conoce (no sobreescribir con -1),
                // para que la próxima transición motor on/off se detecte correctamente.
                if (isset($trama['accstatus']) && $trama['accstatus'] !== -1) {
                    $payload['last_accstatus'] = $trama['accstatus'];
                }

                // Persistir el odómetro virtual acumulado (distancia entre puntos).
                if (isset($trama['odometer_meters'])) {
                    $payload['odometer_meters'] = $trama['odometer_meters'];
                }

                $device->update($payload);
            }
        } catch (\Exception $e) {
            Log::channel('sateltrack')->error('Error al actualizar el dispositivo tras envío a SatelTrack', [
                'imei' => $trama['imei'] ?? 'N/A',
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function updateCounterService(int $successCount, int $failedCount, int $totalSent, ?string $lastError): void
    {
        try {
            DB::transaction(function () use ($successCount, $failedCount, $totalSent, $lastError) {
                $counterService = $this->config->counterServices()->firstOrCreate(
                    [
                        'serviceable_type' => Config::class,
                        'serviceable_id' => $this->config->id,
                    ],
                    ['data' => []]
                );

                $data = $counterService->data ?? [];

                $data['sent'] = ($data['sent'] ?? 0) + $totalSent;
                $data['success'] = ($data['success'] ?? 0) + $successCount;
                $data['failed'] = ($data['failed'] ?? 0) + $failedCount;
                $data['last_error'] = $lastError;
                $data['last_attempt'] = now()->toDateTimeString();

                $counterService->update(['data' => $data]);
            });
        } catch (\Exception $e) {
            Log::channel('sateltrack')->error('Error al actualizar contadores de SatelTrack', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
