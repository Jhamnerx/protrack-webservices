<?php

namespace App\Services\Api;

use App\Models\Config;
use ProtrackApi\ProtrackApiClient;
use ProtrackApi\Resources\Auth;
use ProtrackApi\Resources\Device as ApiDevice;
use ProtrackApi\Resources\Track as ApiTrack;
use Illuminate\Support\Facades\Cache;

class ProtrackApiService
{
    protected string $baseUri = 'http://api.protrack365.com';
    protected ProtrackApiClient $client;

    public function __construct()
    {
        $this->client = new ProtrackApiClient($this->baseUri);
    }

    /**
     * Obtiene el access token de la API y lo guarda en caché.
     *
     * @return string
     * @throws \Exception
     */
    public function getAccessToken(): string
    {

        $cacheKey = 'protrack_api_access_token';
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Reemplaza 'test' y 'Abc@34590' por tus credenciales reales.
        $auth = new Auth($this->client);
        $config  = Config::first();

        if (!$config->cuenta || !$config->clave) {
            throw new \Exception('No se encontró la configuración de la API');
        }

        $authResponse = $auth->getAccessToken($config->cuenta, $config->clave);

        if (!isset($authResponse['record']['access_token'])) {
            throw new \Exception('No se pudo obtener el access token');
        }

        $accessToken = $authResponse['record']['access_token'];
        $expiresIn = $authResponse['record']['expires_in'] ?? 7200;

        // Almacena el token en caché por la cantidad de segundos indicados
        Cache::put($cacheKey, $accessToken, $expiresIn);

        return $accessToken;
    }

    /**
     * Consulta la API para obtener la lista de dispositivos.
     *
     * @return array
     * @throws \Exception
     */
    public function fetchDevices(): array
    {
        $accessToken = $this->getAccessToken();

        $apiDevice = new ApiDevice($this->client);
        $response = $apiDevice->listDevices($accessToken);

        if ($response['code'] !== 0) {
            throw new \Exception('Error al obtener dispositivos de la API');
        }

        return $response['record'] ?? [];
    }

    /**
     * Consulta la API para obtener el estado de los dispositivos.
     *
     * @param array $imeis
     * @return array
     * @throws \Exception
     */
    public function fetchDeviceStatus(array $imeis): array
    {
        $accessToken = $this->getAccessToken();

        $apiDevice = new ApiDevice($this->client);
        $response = $apiDevice->getDeviceDetails($accessToken, $imeis);

        if ($response['code'] !== 0) {
            throw new \Exception('Error al obtener el estado de los dispositivos de la API');
        }

        return $response['record'] ?? [];
    }

    /**
     * Consulta la API para obtener la ubicación de los dispositivos.
     *
     * @param array $imeis
     * @return array
     * @throws \Exception
     */
    public function fetchDeviceLocation(array $imeis): array
    {
        $accessToken = $this->getAccessToken();

        $apiDevice = new ApiTrack($this->client);
        $response = $apiDevice->trackDevices($accessToken, $imeis);

        if ($response['code'] !== 0) {
            throw new \Exception('Error al obtener la ubicación de los dispositivos de la API');
        }

        return $response['record'] ?? [];
    }
}
