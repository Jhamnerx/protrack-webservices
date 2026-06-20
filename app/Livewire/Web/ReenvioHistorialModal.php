<?php

namespace App\Livewire\Web;

use Carbon\Carbon;
use App\Models\Devices;
use Livewire\Component;
use Livewire\Attributes\On;
use App\Jobs\ReenviarHistorial;
use Illuminate\Support\Facades\Log;
use App\Jobs\ReenviarHistorialSatelTrack;

class ReenvioHistorialModal extends Component
{
    public $showModal = false;
    public $device = null;
    public $startDate;
    public $endDate;
    public $service = 'osinergmin';

    /** Nombres legibles por servicio para mostrar en la UI. */
    protected array $serviceLabels = [
        'osinergmin' => 'Osinergmin',
        'sateltrack' => 'Tracklog',
    ];

    protected $listeners = [
        'openReenvioModalDevice' => 'openModal'
    ];

    public function mount()
    {
        // Inicializar fechas por defecto (últimas 24 horas)
        $this->endDate = Carbon::now('America/Lima')->format('Y-m-d H:i');
        $this->startDate = Carbon::now('America/Lima')->subDay()->format('Y-m-d H:i');
    }

    public function render()
    {
        return view('livewire.web.reenvio-historial-modal');
    }

    public function openModal($deviceId, $service = 'osinergmin')
    {
        $this->device = Devices::find($deviceId);
        $this->service = $service;

        if (!$this->device) {
            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'Error',
                mensaje: 'Dispositivo no encontrado'
            );
            return;
        }

        // Resetear fechas a las últimas 24 horas
        $this->endDate = Carbon::now('America/Lima')->format('Y-m-d H:i');
        $this->startDate = Carbon::now('America/Lima')->subDay()->format('Y-m-d H:i');

        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->device = null;
        $this->reset(['startDate', 'endDate']);
    }

    public function reenviarHistorial()
    {
        $this->validate([
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
        ], [
            'startDate.required' => 'La fecha de inicio es requerida',
            'startDate.date' => 'La fecha de inicio debe ser una fecha válida',
            'endDate.required' => 'La fecha de fin es requerida',
            'endDate.date' => 'La fecha de fin debe ser una fecha válida',
            'endDate.after' => 'La fecha de fin debe ser posterior a la fecha de inicio',
        ]);

        if (!$this->device) {
            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'Error',
                mensaje: 'No se ha seleccionado un dispositivo'
            );
            return;
        }

        try {
            // Convertir fechas a zona horaria de Lima (soporta tanto 'Y-m-d H:i' como 'Y-m-d\TH:i')
            $startDateTime = Carbon::parse($this->startDate, 'America/Lima');
            $endDateTime = Carbon::parse($this->endDate, 'America/Lima');

            // Validar que el rango no sea mayor a 30 días
            if ($startDateTime->diffInDays($endDateTime) > 30) {
                $this->dispatch(
                    'notify-toast',
                    icon: 'warning',
                    title: 'Advertencia',
                    mensaje: 'El rango de fechas no puede ser mayor a 7 días'
                );
                return;
            }

            // Dispatch del job según el servicio destino
            $jobClass = $this->service === 'sateltrack'
                ? ReenviarHistorialSatelTrack::class
                : ReenviarHistorial::class;

            $jobClass::dispatch(
                $this->device->imei,
                $startDateTime->format('Y-m-d H:i:s'),
                $endDateTime->format('Y-m-d H:i:s')
            );

            $serviceLabel = $this->serviceLabels[$this->service] ?? $this->service;

            Log::info("Job de reenvío de historial despachado manualmente", [
                'service' => $this->service,
                'device_imei' => $this->device->imei,
                'device_plate' => $this->device->plate,
                'start_date' => $startDateTime->format('Y-m-d H:i:s'),
                'end_date' => $endDateTime->format('Y-m-d H:i:s'),
                'user_triggered' => true
            ]);

            $this->dispatch(
                'notify-toast',
                icon: 'success',
                title: 'Job Despachado',
                mensaje: "Se ha iniciado el reenvío de historial a {$serviceLabel} para {$this->device->plate} desde {$startDateTime->format('d/m/Y H:i')} hasta {$endDateTime->format('d/m/Y H:i')}"
            );

            $this->closeModal();
        } catch (\Exception $e) {
            Log::error("Error al despachar job de reenvío de historial", [
                'error' => $e->getMessage(),
                'device_imei' => $this->device->imei ?? 'N/A'
            ]);

            $this->dispatch(
                'notify-toast',
                icon: 'error',
                title: 'Error',
                mensaje: 'Error al procesar el reenvío: ' . $e->getMessage()
            );
        }
    }

    public function setLast24Hours()
    {
        $this->endDate = Carbon::now('America/Lima')->format('Y-m-d H:i');
        $this->startDate = Carbon::now('America/Lima')->subDay()->format('Y-m-d H:i');
    }

    public function setLast7Days()
    {
        $this->endDate = Carbon::now('America/Lima')->format('Y-m-d H:i');
        $this->startDate = Carbon::now('America/Lima')->subDays(7)->format('Y-m-d H:i');
    }

    public function setToday()
    {
        $today = Carbon::now('America/Lima');
        $this->startDate = $today->startOfDay()->format('Y-m-d H:i');
        $this->endDate = $today->endOfDay()->format('Y-m-d H:i');
    }
}
