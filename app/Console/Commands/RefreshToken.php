<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Api\ProtrackApiService;

class RefreshToken extends Command
{
    protected $signature = 'protrack:refresh-token';
    protected $description = 'Obtiene y almacena el accessToken';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(ProtrackApiService $protrackApiService)
    {
        $maxAttempts = 3; // Número máximo de intentos
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                $attempt++;
                // Obtiene el access token
                $token = $protrackApiService->getAccessToken();
                $this->info("Access token obtenido y almacenado en caché: {$token}");
                return 0; // Éxito
            } catch (\Exception $e) {
                $this->error("Intento {$attempt}: Error al obtener el access token: {$e->getMessage()}");

                if ($attempt < $maxAttempts) {
                    $this->info("Esperando 1 minuto antes de reintentar...");
                    sleep(60); // Espera 1 minuto
                } else {
                    $this->error("Se alcanzó el número máximo de intentos. Abortando.");
                    return 1; // Código de error
                }
            }
        }
    }
}
