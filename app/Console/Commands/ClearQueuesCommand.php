<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ClearQueuesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:clear-all {--force : Force the operation without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpiar todas las colas de trabajos del sistema';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Verificar si se debe forzar o pedir confirmación
        if (!$this->option('force') && !$this->confirm('¿Estás seguro de que quieres limpiar todas las colas?')) {
            $this->info('Operación cancelada.');
            return Command::FAILURE;
        }

        $this->info('Iniciando limpieza de colas...');

        // Lista de colas a limpiar
        $queues = [
            'web-services',
            'web-services-sutran',
            'reenviar-historial',
            'default'
        ];

        $cleared = 0;
        $errors = 0;

        foreach ($queues as $queue) {
            try {
                $this->info("Limpiando cola: {$queue}");

                // Intentar limpiar la cola
                $exitCode = Artisan::call('queue:clear', [
                    '--queue' => $queue,
                    '--force' => true,
                ]);

                if ($exitCode === 0) {
                    $this->line("✅ Cola '{$queue}' limpiada exitosamente");
                    $cleared++;

                    // Log de éxito
                    Log::info("Cola limpiada mediante comando", [
                        'queue' => $queue,
                        'method' => 'command',
                        'timestamp' => now()
                    ]);
                } else {
                    $this->error("❌ Error al limpiar la cola '{$queue}'");
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->error("❌ Excepción al limpiar la cola '{$queue}': " . $e->getMessage());
                $errors++;

                Log::error("Error al limpiar cola mediante comando", [
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                    'timestamp' => now()
                ]);
            }
        }

        // Resumen
        $this->newLine();
        $this->info("📊 Resumen de limpieza:");
        $this->line("✅ Colas limpiadas exitosamente: {$cleared}");
        $this->line("❌ Errores encontrados: {$errors}");
        $this->line("📅 Fecha y hora: " . now()->format('d/m/Y H:i:s'));

        if ($errors === 0) {
            $this->info('🎉 ¡Todas las colas fueron limpiadas exitosamente!');
            return Command::SUCCESS;
        } else {
            $this->warn('⚠️  Se completó con algunos errores. Revisa los logs para más detalles.');
            return Command::FAILURE;
        }
    }
}
