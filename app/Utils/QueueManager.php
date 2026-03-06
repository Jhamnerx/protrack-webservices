<?php

namespace App\Utils;

use App\Jobs\ClearQueues;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class QueueManager
{
    /**
     * Limpiar cola de servicios web Sutran
     */
    public static function clearSutranQueue(): string
    {
        return ClearQueues::clearSpecificQueue('web-services-sutran');
    }

    /**
     * Limpiar cola de servicios web general
     */
    public static function clearWebServicesQueue(): string
    {
        return ClearQueues::clearSpecificQueue('web-services');
    }

    /**
     * Limpiar cola de reenvío de historial
     */
    public static function clearHistorialQueue(): string
    {
        return ClearQueues::clearSpecificQueue('reenviar-historial');
    }

    /**
     * Limpiar cola por defecto
     */
    public static function clearDefaultQueue(): string
    {
        return ClearQueues::clearSpecificQueue('default');
    }

    /**
     * Limpiar todas las colas
     */
    public static function clearAllQueues(): string
    {
        return ClearQueues::clearAllQueues();
    }

    /**
     * Limpiar cola personalizada
     */
    public static function clearCustomQueue(string $queueName): string
    {
        return ClearQueues::clearSpecificQueue($queueName);
    }

    /**
     * Obtener información sobre el estado de las colas
     */
    public static function getQueueInfo(): array
    {
        try {
            // Ejecutar comando queue:monitor para obtener información
            Artisan::call('queue:monitor', [
                '--max' => 10
            ]);

            return [
                'status' => 'success',
                'output' => Artisan::output(),
                'timestamp' => now()
            ];
        } catch (\Exception $e) {
            Log::error('Error al obtener información de colas', [
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => now()
            ];
        }
    }

    /**
     * Reiniciar workers de cola
     */
    public static function restartQueueWorkers(): string
    {
        try {
            Artisan::call('queue:restart');
            $output = Artisan::output();

            Log::info('Workers de cola reiniciados', [
                'output' => $output,
                'timestamp' => now()
            ]);

            return $output;
        } catch (\Exception $e) {
            Log::error('Error al reiniciar workers de cola', [
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            throw $e;
        }
    }

    /**
     * Limpiar jobs fallidos
     */
    public static function clearFailedJobs(): string
    {
        try {
            Artisan::call('queue:flush');
            $output = Artisan::output();

            Log::info('Jobs fallidos limpiados', [
                'output' => $output,
                'timestamp' => now()
            ]);

            return $output;
        } catch (\Exception $e) {
            Log::error('Error al limpiar jobs fallidos', [
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            throw $e;
        }
    }
}
