<?php

namespace App\Jobs;


use App\Models\Config;
use App\Models\Devices;
use App\Jobs\SendToSutranJob;
use App\Jobs\SendToOsinergminJob;
use Illuminate\Support\Facades\Log;
use App\Services\Processors\Processor;
use Illuminate\Queue\SerializesModels;
use App\Services\Api\ProtrackApiService;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Api\DeviceStatusService;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;


class ProcessUnitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1200;

    public function __construct()
    {
        $this->onQueue('web-services');
    }

    public function handle()
    {
        $config = Config::first();

        $devices = Devices::where(function ($query) {
            $query->where('services->sutran->active', true)
                ->orWhere('services->osinergmin->active', true);
        })->get();

        $sutranDevices = $this->filterSutranDevices($devices);

        $combinedDevices = array_unique(array_merge($sutranDevices));

        $protrackService = new ProtrackApiService();

        $chunks = array_chunk($combinedDevices, 100);

        $result = [];
        foreach ($chunks as $chunk) {

            $imeis = implode(',', array_map('intval', array_values($chunk)));


            $partialResult = $protrackService->fetchDeviceLocation($chunk);

            Log::info('result:' . json_encode($partialResult));

            $result = array_merge($result, $partialResult);
        }

        // Verificar si $result tiene datos
        if (empty($result)) {
            Log::info('No se encontraron datos para los dispositivos.');
            return;
        }

        $processor = new Processor();
        $processedUnits = $processor->processUnits($result);

        if ($config->servicios['sutran']['status'])
            if (!empty($processedUnits['sutran']) && $config->servicios['sutran']['status']) {

                SendToSutranJob::dispatch($processedUnits['sutran']);
            }
    }

    private function filterSutranDevices($devices)
    {
        return $devices->filter(function ($unit) {
            return $unit->services['sutran']['active'] ?? false;
        })->pluck('imei')->toArray();
    }

    private function filterOsinergminDevices($devices)
    {
        return $devices->filter(function ($unit) {
            return $unit->services['osinergmin']['active'] ?? false;
        })->pluck('imei')->toArray();
    }
}
