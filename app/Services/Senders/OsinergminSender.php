<?php

namespace App\Services\Senders;

use App\Models\Config;
use App\Models\Devices;
use Carbon\Carbon;
use GuzzleHttp\Client;
use App\Services\LogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Promise;
use Psr\Http\Message\ResponseInterface;

class OsinergminSender implements UnitSenderInterface
{
    public $logService;
    protected $config;
    protected $client;
    protected $maxConcurrentRequests = 5;
    protected $senderName = 'Osinergmin';

    public function __construct()
    {
        $this->logService = app(LogService::class);
        $this->config = Config::first();
        $this->client = new Client([
            'verify' => false,
            'connect_timeout' => 5,
            'timeout' => 10,
        ]);
    }

    public function send(array $tramas, $url): void
    {
        if (empty($tramas)) {
            Log::info('No hay tramas para enviar a Osinergmin');
            return;
        }

        // Log inicio del batch siempre
        Log::channel('osinergmin')->info('Iniciando envío de batch', [
            'total_tramas' => count($tramas),
            'url' => $url,
            'timestamp' => now()->toDateTimeString()
        ]);

        // Verificar si debemos usar el método optimizado por lotes
        if (count($tramas) > 10) {
            $this->sendBatch($tramas, $url);
            return;
        }

        $successCount = 0;
        $failedCount = 0;

        foreach ($tramas as $trama) {
            try {
                $response = $this->client->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode($trama),
                ]);

                $responseBody = json_decode($response->getBody()->getContents(), true);

                Log::channel('osinergmin')->debug('Respuesta de Osinergmin', [
                    'plate' => $trama['plate'] ?? 'N/A',
                    'imei' => $trama['imei'] ?? 'N/A',
                    'response' => $responseBody,
                ]);

                if (isset($responseBody['status']) && $responseBody['status'] === 'CREATED') {
                    $successCount++;
                    $this->handleSuccess($responseBody, $trama);
                } else {
                    $failedCount++;
                    $this->handleError($responseBody, $trama);
                }
            } catch (RequestException $e) {
                $failedCount++;

                $errorResponse = null;
                if ($e->hasResponse()) {
                    $errorResponse = json_decode($e->getResponse()->getBody()->getContents(), true);
                }

                Log::channel('osinergmin')->error('Error al enviar trama', [
                    'plate' => $trama['plate'] ?? 'N/A',
                    'imei' => $trama['imei'] ?? 'N/A',
                    'event' => $trama['event'] ?? 'N/A',
                    'error' => $e->getMessage(),
                    'api_response' => $errorResponse,
                    'trama' => $trama
                ]);

                if ($this->config->servicios['osinergmin']['enabled_logs']) {
                    $this->logError($e, $trama);
                }
            } catch (\Exception $e) {
                $failedCount++;

                Log::channel('osinergmin')->error('Error inesperado al enviar trama', [
                    'plate' => $trama['plate'] ?? 'N/A',
                    'imei' => $trama['imei'] ?? 'N/A',
                    'error' => $e->getMessage(),
                    'trama' => $trama
                ]);
            }
        }

        // Log finalización del batch siempre
        Log::channel('osinergmin')->info('Batch completado', [
            'total_sent' => count($tramas),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'success_rate' => count($tramas) > 0 ? round(($successCount / count($tramas)) * 100, 2) . '%' : '0%',
            'timestamp' => now()->toDateTimeString()
        ]);

        // Actualizar contadores globales después de procesar todas las tramas
        $this->updateCounterService($successCount, $failedCount, count($tramas));
    }

    /**
     * Enviar tramas por lotes para optimizar el rendimiento
     */
    protected function sendBatch(array $tramas, $url): void
    {
        $successCount = 0;
        $failedCount = 0;
        $chunks = array_chunk($tramas, $this->maxConcurrentRequests);

        Log::info("Enviando {$this->maxConcurrentRequests} tramas simultáneas a Osinergmin (total: " . count($tramas) . ")");

        foreach ($chunks as $chunk) {
            $requests = function ($chunk) use ($url) {
                foreach ($chunk as $trama) {
                    yield function () use ($url, $trama) {
                        return $this->client->postAsync($url, [
                            'headers' => [
                                'Content-Type' => 'application/json',
                            ],
                            'body' => json_encode($trama),
                            'trama' => $trama, // Pasar la trama como metadata
                        ]);
                    };
                }
            };

            $pool = new Pool($this->client, $requests($chunk), [
                'concurrency' => $this->maxConcurrentRequests,
                'fulfilled' => function (ResponseInterface $response, $index) use ($chunk, &$successCount, &$failedCount) {
                    try {
                        $responseBody = json_decode($response->getBody()->getContents(), true);
                        $trama = $chunk[$index];

                        Log::channel('osinergmin')->debug('Respuesta de Osinergmin (batch)', [
                            'plate' => $trama['plate'] ?? 'N/A',
                            'imei' => $trama['imei'] ?? 'N/A',
                            'response' => $responseBody,
                        ]);

                        if (isset($responseBody['status']) && $responseBody['status'] === 'CREATED') {
                            $successCount++;
                            $this->handleSuccess($responseBody, $trama);
                        } else {
                            $failedCount++;
                            $this->handleError($responseBody, $trama);
                        }
                    } catch (\Exception $e) {
                        $failedCount++;
                        Log::channel('osinergmin')->error('Error procesando respuesta en batch', [
                            'error' => $e->getMessage(),
                            'trama' => $chunk[$index] ?? null,
                        ]);
                    }
                },
                'rejected' => function ($reason, $index) use ($chunk, &$failedCount) {
                    $failedCount++;
                    $trama = $chunk[$index];

                    $errorResponse = null;
                    if ($reason instanceof RequestException && $reason->hasResponse()) {
                        $errorResponse = json_decode($reason->getResponse()->getBody()->getContents(), true);
                    }

                    Log::channel('osinergmin')->error('Error al enviar trama en batch', [
                        'plate' => $trama['plate'] ?? 'N/A',
                        'imei' => $trama['imei'] ?? 'N/A',
                        'event' => $trama['event'] ?? 'N/A',
                        'error' => $reason instanceof \Exception ? $reason->getMessage() : (string) $reason,
                        'api_response' => $errorResponse,
                        'trama' => $trama
                    ]);

                    if ($reason instanceof RequestException && $this->config->servicios['osinergmin']['enabled_logs']) {
                        $this->logError($reason, $trama);
                    }
                }
            ]);

            // Esperar a que se completen todas las solicitudes
            $promise = $pool->promise();
            $promise->wait();
        }

        // Log finalización del batch siempre
        Log::channel('osinergmin')->info('Batch completado', [
            'total_sent' => count($tramas),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'success_rate' => count($tramas) > 0 ? round(($successCount / count($tramas)) * 100, 2) . '%' : '0%',
            'timestamp' => now()->toDateTimeString()
        ]);

        // Actualizar contadores globales después de procesar todas las tramas
        $this->updateCounterService($successCount, $failedCount, count($tramas));
    }

    protected function handleSuccess(array $response, array $trama): void
    {
        // Log de éxito siempre
        Log::channel('osinergmin')->info('Trama enviada exitosamente', [
            'plate' => $trama['plate'] ?? 'N/A',
            'imei' => $trama['imei'] ?? 'N/A',
            'event' => $trama['event'] ?? 'N/A',
            'trama' => $trama,
            'response' => $response
        ]);

        if ($this->config->servicios['osinergmin']['enabled_logs'] ?? true) {

            $this->logService->logToDatabase(
                '',
                'Osinergmin',
                $trama['plate'],
                'success',
                $trama,
                ['message' => $response],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        }

        try {
            $device = Devices::where('imei', $trama['imei'])->first();

            if ($device) {
                $device->update([
                    'last_status' => $trama['event'],
                    'last_position' => $trama['position'],
                    'last_update' => Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                    'latest_position_id' => $trama['idTrama'],
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error al actualizar el dispositivo después del envío a Osinergmin: " . $e->getMessage());
        }
    }

    protected function handleError(?array $response, array $trama): void
    {
        $errorMessage = ($response['message'] ?? 'Sin mensaje') . ' - ' . ($response['suggestion'] ?? 'Sin sugerencia');

        // Log de error siempre
        Log::channel('osinergmin')->error('Error respuesta de Osinergmin', [
            'plate' => $trama['plate'] ?? 'N/A',
            'imei' => $trama['imei'] ?? 'N/A',
            'event' => $trama['event'] ?? 'N/A',
            'error' => $errorMessage,
            'trama' => $trama,
            'response' => $response
        ]);

        if ($this->config->servicios['osinergmin']['enabled_logs']) {
            $this->logService->logToDatabase(
                '',
                'Osinergmin',
                $trama['plate'],
                $response['status'] ?? 'error',
                $trama,
                ['message' => $errorMessage],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        }
    }

    protected function logError(RequestException $e, array $trama): void
    {
        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);

            $this->logService->logToDatabase(
                '',
                'Osinergmin',
                $trama['plate'],
                $body['status'] ?? 'error',
                $trama,
                ['message' => ($body['message'] ?? 'Error de conexión') . ' - ' . ($body['suggestion'] ?? 'No suggestion')],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        } else {
            // Error sin respuesta (conexión, timeout, etc.)
            $this->logService->logToDatabase(
                '',
                'Osinergmin',
                $trama['plate'],
                'error',
                $trama,
                ['message' => 'Error de conexión: ' . $e->getMessage()],
                [],
                Carbon::parse($trama['gpsDate'])->setTimezone('America/Lima')->format('Y-m-d H:i:s'),
                $trama['imei']
            );
        }
    }

    protected function updateCounterService(int $successCount, int $failedCount, int $totalSent): void
    {
        try {
            DB::transaction(function () use ($successCount, $failedCount, $totalSent) {
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
                $data['last_error'] = $failedCount > 0 ? 'Errores en algunas tramas' : null;
                $data['last_attempt'] = now()->toDateTimeString();

                $counterService->update(['data' => $data]);
            });
        } catch (\Exception $e) {
            Log::error("Error al actualizar contadores de servicio: " . $e->getMessage());
        }
    }
}
