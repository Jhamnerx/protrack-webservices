<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\Api\ProtrackApiService;
use Illuminate\Http\JsonResponse;

class ApiController extends Controller
{
    protected ProtrackApiService $protrackService;

    public function __construct(ProtrackApiService $protrackService)
    {
        $this->protrackService = $protrackService;
    }

    public function index()
    {
        return view('web.index');
    }

    public function config()
    {
        return view('web.config');
    }

    /**
     * Obtiene el historial de ubicaciones de un dispositivo.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPlayback(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'imei' => 'required|string',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s'
            ]);

            $result = $this->protrackService->getDevicePlaybackByDateRange(
                $request->imei,
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Se obtuvieron {$result['total']} registros de ubicación"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Obtiene el historial de ubicaciones de las últimas 24 horas de un dispositivo.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getPlaybackLast24h(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'imei' => 'required|string'
            ]);

            $result = $this->protrackService->getDevicePlaybackLast24Hours($request->imei);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Se obtuvieron {$result['total']} registros de ubicación de las últimas 24 horas"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
