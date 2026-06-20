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
    public function getAccessToken(bool $forceRefresh = false): string
    {

        $cacheKey = 'protrack_api_access_token';

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

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

        if (isset($authResponse['code']) && $authResponse['code'] !== 0) {
            throw new \Exception($authResponse['message'] ?? 'Error al obtener el access token: código ' . $authResponse['code']);
        }

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
            throw new \Exception($response['message'] ?? 'Error al obtener dispositivos de la API');
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
            throw new \Exception($response['message'] ?? 'Error al obtener el estado de los dispositivos de la API');
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
            throw new \Exception($response['message'] ?? 'Error al obtener la ubicación de los dispositivos de la API');
        }

        return $response['record'] ?? [];
    }

    /**
     * Consulta la API para obtener el historial de ubicaciones (playback) de un dispositivo.
     *
     * @param string $imei
     * @param int $beginTime
     * @param int $endTime
     * @return array
     * @throws \Exception
     */
    public function fetchPlayback(string $imei, int $beginTime, int $endTime): array
    {
        $accessToken = $this->getAccessToken();
        $allRecords = [];
        $totalRecords = 0;
        $currentBeginTime = $beginTime;
        $lastCode = null;

        do {
            $url = "{$this->baseUri}/api/playback";
            $params = [
                'access_token' => $accessToken,
                'imei' => $imei,
                'begintime' => $currentBeginTime,
                'endtime' => $endTime
            ];

            $response = $this->makeHttpRequest($url, $params);

            if (!$response || !isset($response['code'])) {
                throw new \Exception('Respuesta inválida de la API de playback');
            }

            $lastCode = $response['code'];

            if ($response['code'] !== 0) {
                throw new \Exception($response['message'] ?? 'Error en la API de playback: código ' . $response['code']);
            }

            if (empty($response['record'])) {
                break;
            }

            // Procesar los registros
            $records = $this->parsePlaybackRecords($response['record']);
            $recordCount = count($records);
            $totalRecords += $recordCount;

            // Agregar los registros al array total
            $allRecords = array_merge($allRecords, $records);

            // Si obtuvimos menos de 1000 registros, hemos terminado
            if ($recordCount < 1000) {
                break;
            }

            // Para la siguiente iteración, usar el tiempo del último registro como begintime
            if (!empty($records)) {
                $lastRecord = end($records);
                $currentBeginTime = $lastRecord['gpstime'];
            }

            // Prevenir bucle infinito
            if ($currentBeginTime >= $endTime) {
                break;
            }
        } while (true);

        return [
            'records' => $allRecords,
            'code' => $lastCode,
            'total' => $totalRecords
        ];
    }

    /**
     * Consulta la API de alarmas (/api/alarm/list) para un dispositivo y rango de tiempo.
     *
     * Respuesta cruda: registros separados por ';', cada uno con campos separados por ','
     * en el orden: alarmType, longitude, latitude, gpstime, systemtime, speed, course, geofenceId.
     * Máximo 100 registros por petición; se pagina usando el systemtime del último registro.
     *
     * @return array{records: array, code: int|null, total: int}
     * @throws \Exception
     */
    public function fetchAlarms(string $imei, int $beginTime, int $endTime): array
    {
        $accessToken = $this->getAccessToken();
        $allRecords = [];
        $totalRecords = 0;
        $currentBeginTime = $beginTime;
        $lastCode = null;

        do {
            $url = "{$this->baseUri}/api/alarm/list";
            $params = [
                'access_token' => $accessToken,
                'imei' => $imei,
                'begintime' => $currentBeginTime,
                'endtime' => $endTime,
            ];

            $response = $this->makeHttpRequest($url, $params);

            if (!$response || !isset($response['code'])) {
                throw new \Exception('Respuesta inválida de la API de alarmas');
            }

            $lastCode = $response['code'];

            if ($response['code'] !== 0) {
                throw new \Exception($response['message'] ?? 'Error en la API de alarmas: código ' . $response['code']);
            }

            if (empty($response['record'])) {
                break;
            }

            $records = $this->parseAlarmRecords($response['record']);
            $recordCount = count($records);
            $totalRecords += $recordCount;

            $allRecords = array_merge($allRecords, $records);

            // Si obtuvimos menos de 100 registros, hemos terminado.
            if ($recordCount < 100) {
                break;
            }

            // Para la siguiente página usar el systemtime del último registro como begintime.
            $lastRecord = end($records);
            $nextBeginTime = $lastRecord['systemtime'];

            // Prevenir bucle infinito si el cursor no avanza.
            if ($nextBeginTime <= $currentBeginTime) {
                break;
            }

            $currentBeginTime = $nextBeginTime;

            if ($currentBeginTime >= $endTime) {
                break;
            }
        } while (true);

        return [
            'records' => $allRecords,
            'code' => $lastCode,
            'total' => $totalRecords,
        ];
    }

    /**
     * Procesa la cadena de alarmas (formato CSV separado por ';') a un array estructurado.
     *
     * @return array
     */
    private function parseAlarmRecords(string $recordString): array
    {
        if (empty($recordString)) {
            return [];
        }

        $records = [];
        $recordGroups = explode(';', $recordString);

        foreach ($recordGroups as $group) {
            if (empty(trim($group))) {
                continue;
            }

            $data = explode(',', $group);

            if (count($data) >= 7) {
                $records[] = [
                    'alarmType' => (int) $data[0],
                    'longitude' => (float) $data[1],
                    'latitude' => (float) $data[2],
                    'gpstime' => (int) $data[3],
                    'systemtime' => (int) $data[4],
                    'speed' => (int) $data[5],
                    'course' => (int) $data[6],
                    'geofenceId' => $data[7] ?? null,
                ];
            }
        }

        return $records;
    }

    /**
     * Realiza una petición HTTP GET a la URL especificada.
     *
     * @param string $url
     * @param array $params
     * @return array|null
     */
    private function makeHttpRequest(string $url, array $params): ?array
    {
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => [
                    'Content-Type: application/json',
                    'User-Agent: ProtrackWebServices/1.0'
                ]
            ]
        ]);

        $response = file_get_contents($fullUrl, false, $context);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Procesa los registros de playback desde el formato de cadena a array.
     *
     * @param string $recordString
     * @return array
     */
    private function parsePlaybackRecords(string $recordString): array
    {
        if (empty($recordString)) {
            return [];
        }

        $records = [];
        $recordGroups = explode(';', $recordString);

        foreach ($recordGroups as $group) {
            if (empty(trim($group))) {
                continue;
            }

            $data = explode(',', $group);

            if (count($data) >= 5) {
                $records[] = [
                    'longitude' => (float) $data[0],
                    'latitude' => (float) $data[1],
                    'gpstime' => (int) $data[2],
                    'speed' => (int) $data[3],
                    'course' => (int) $data[4],
                    'datetime' => date('Y-m-d H:i:s', $data[2]) // Conversión a fecha legible
                ];
            }
        }

        return $records;
    }

    /**
     * Ejemplo de uso del método fetchPlayback.
     * Obtiene el historial de ubicaciones de un dispositivo para las últimas 24 horas.
     *
     * @param string $imei
     * @return array
     * @throws \Exception
     */
    public function getDevicePlaybackLast24Hours(string $imei): array
    {
        $endTime = time(); // Tiempo actual
        $beginTime = $endTime - (24 * 60 * 60); // 24 horas atrás

        return $this->fetchPlayback($imei, $beginTime, $endTime);
    }

    /**
     * Obtiene el historial de ubicaciones de un dispositivo para un rango de fechas específico.
     *
     * @param string $imei
     * @param string $startDate Fecha de inicio en formato 'Y-m-d H:i:s'
     * @param string $endDate Fecha de fin en formato 'Y-m-d H:i:s'
     * @return array
     * @throws \Exception
     */
    public function getDevicePlaybackByDateRange(string $imei, string $startDate, string $endDate): array
    {
        $beginTime = strtotime($startDate);
        $endTime = strtotime($endDate);

        if ($beginTime === false || $endTime === false) {
            throw new \Exception('Formato de fecha inválido. Use Y-m-d H:i:s');
        }

        if ($beginTime >= $endTime) {
            throw new \Exception('La fecha de inicio debe ser anterior a la fecha de fin');
        }

        return $this->fetchPlayback($imei, $beginTime, $endTime);
    }
}
