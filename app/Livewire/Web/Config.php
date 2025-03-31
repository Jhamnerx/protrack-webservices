<?php

namespace App\Livewire\Web;

use Carbon\Carbon;
use App\Models\Devices;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Collection;
use App\Models\Config as ConfigApp;
use Illuminate\Support\Facades\Redis;
use App\Services\Api\ProtrackApiService;

class Config extends Component
{
    use WithPagination;
    public $cuenta, $clave;
    public Collection $servicios;
    public ConfigApp $config;

    public $search;
    public $search_accounts;
    public array $values = [];

    public function mount()
    {

        $config = ConfigApp::first();

        $this->cuenta = $config->cuenta;
        $this->clave = $config->clave;
        $this->servicios = collect($config->servicios);
        $this->config = $config;
    }


    public function render()
    {
        $unidades = Devices::where('plate', 'like', '%' . $this->search . '%')
            ->Orwhere('type', 'like', '%' . $this->search . '%')
            ->Orwhere('name', 'like', '%' . $this->search . '%')
            ->Orwhere('imei', 'like', '%' . $this->search . '%')
            ->paginate(10, pageName: 'unidades-page');

        return view('livewire.web.config', compact('unidades'));
    }


    public function updatedSearch()
    {
        $this->resetPage(pageName: 'unidades-page');
    }

    public function loadUnits()
    {

        $this->fetchAndStoreDevices();

        $this->dispatch(
            'notify-toast',
            icon: 'success',
            title: 'UNIDADES SINCRONIZADAS',
            mensaje: 'Las unidades se han sincronizado correctamente'
        );
    }

    public function fetchAndStoreDevices()
    {
        try {
            // Instancia el servicio de Protrack
            $protrackService = new ProtrackApiService();
            // Obtiene los dispositivos desde la API
            $devices = $protrackService->fetchDevices();

            $services = $this->servicios->keys()->all();
            // Recorre y almacena (o crea) cada dispositivo
            foreach ($devices as $deviceData) {
                // Inicializa un array para los servicios de esta unidad
                $unitServices = [];

                // Por cada servicio, agrega una entrada con 'active' en false
                foreach ($services as $service) {
                    $unitServices[$service] = ['active' => false];
                }

                // Crea el dispositivo solo si no existe
                Devices::firstOrCreate(
                    ['imei' => $deviceData['imei']], // Condición para buscar el dispositivo
                    [ // Valores para crear el dispositivo si no existe
                        'name' => $deviceData['devicename'] ?? null,
                        'plate' => $deviceData['platenumber'] ?? null,
                        'type' => $deviceData['devicetype'] ?? null,
                        'simcard' => $deviceData['simcard'] ?? null,
                        'services' => $unitServices,
                        'last_update' => !empty($deviceData['onlinetime']) && $deviceData['onlinetime'] > 0
                            ? Carbon::createFromTimestamp($deviceData['onlinetime'])
                            : null,
                        'last_position' => $deviceData['platformduetime'] ?? null,
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'Error',
                mensaje: $e->getMessage()
            );
        }
    }

    public function save()
    {

        $this->validate([
            'cuenta' => 'required',
            'clave' => 'required',
            'servicios' => 'required',
        ]);

        try {

            $this->config->update([
                'cuenta' => $this->cuenta,
                'clave' => $this->clave,
                'servicios' => $this->servicios,
            ]);


            $this->dispatch(
                'notify-toast',
                icon: 'success',
                title: 'CONFIGURACIÓN ACTUALIZADA',
                mensaje: 'La configuración se ha actualizado correctamente'
            );
        } catch (\Throwable $th) {
            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'ERROR',
                mensaje: $th->getMessage()
            );
        }
    }


    public function changeServiceStatus(Devices $unit, $service, $status)
    {
        // Validar el formato de la placa
        if (!preg_match('/^[A-Z0-9]{3}-[A-Z0-9]{3}$/', $unit->plate)) {
            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'ERROR',
                mensaje: 'El formato de la placa es incorrecto. Debe ser del tipo ABC-123'
            );
            $this->render();
            return;
        }

        $services = collect($unit->services);
        $services->put($service, ['active' => $status]);
        $unit->update([
            'services' => $services->toArray()
        ]);
    }

    public function saveServicioSutran()
    {
        $datos = $this->validate(
            [
                'servicios.sutran.token' => 'required_if:servicios.sutran.status,true|regex:/^[a-zA-Z0-9]{8}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{4}-[a-zA-Z0-9]{12}$/',
            ],
            [
                'servicios.sutran.token.required_if' => 'El token es requerido cuando el servicio está activo',
                'servicios.sutran.token.regex' => 'El token debe tener 32 caracteres alfanuméricos',
            ]
        );

        $nuevosServicios = array_merge(
            $this->config->servicios,
            ['sutran' => $this->servicios['sutran']]
        );

        $this->config->update([
            'servicios' => $nuevosServicios,
        ]);

        $this->dispatch(
            'notify-toast',
            icon: 'success',
            title: 'SERVICIO ACTUALIZADO',
            mensaje: 'El servicio de SUTRAN se ha actualizado correctamente'
        );
    }


    public function updatedServicios($value, $name)
    {
        if ($name == 'sutran.status' && $value == false) {
            $this->servicios->put('sutran', array_merge($this->servicios['sutran'], ['token' => '']));
        }
    }


    public function openModalReevio()
    {

        $this->dispatch('openModalReenvio');
    }
}
