<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ClearQueues implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Lista de colas a limpiar
            $queues = [
                'web-services',
                'web-services-sutran',
                'reenviar-historial',
                'default'
            ];

            foreach ($queues as $queue) {
                // Ejecuta el comando con --force para evitar confirmación
                Artisan::call('queue:clear', [
                    '--queue' => $queue,
                    '--force' => true,
                ]);

                Log::info("Cola '{$queue}' limpiada exitosamente", [
                    'queue' => $queue,
                    'timestamp' => now(),
                    'output' => Artisan::output()
                ]);
            }

            Log::info('Limpieza programada de colas completada', [
                'queues_cleared' => $queues,
                'total_queues' => count($queues),
                'timestamp' => now()
            ]);
        } catch (\Exception $e) {
            Log::error('Error al limpiar las colas programadas', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'timestamp' => now()
            ]);

            throw $e;
        }
    }

    /**
     * Limpiar una cola específica
     */
    public static function clearSpecificQueue(string $queueName): string
    {
        try {
            Artisan::call('queue:clear', [
                '--queue' => $queueName,
                '--force' => true,
            ]);

            $output = Artisan::output();

            Log::info("Cola específica limpiada manualmente", [
                'queue' => $queueName,
                'output' => $output,
                'timestamp' => now()
            ]);

            return $output;
        } catch (\Exception $e) {
            Log::error("Error al limpiar la cola '{$queueName}'", [
                'queue' => $queueName,
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            throw $e;
        }
    }

    /**
     * Limpiar todas las colas disponibles
     */
    public static function clearAllQueues(): string
    {
        try {
            // Limpiar todas las colas sin especificar cola particular
            Artisan::call('queue:clear', [
                '--force' => true,
            ]);

            $output = Artisan::output();

            Log::info('Todas las colas limpiadas manualmente', [
                'output' => $output,
                'timestamp' => now()
            ]);

            return $output;
        } catch (\Exception $e) {
            Log::error('Error al limpiar todas las colas', [
                'error' => $e->getMessage(),
                'timestamp' => now()
            ]);

            throw $e;
        }
    }
}
