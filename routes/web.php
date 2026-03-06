<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AjustesController;
use App\Http\Controllers\ApiController;
use App\Utils\QueueManager;

Route::middleware([
    'web',
])->group(function () {

    //RUTAS DE DASHBOARD
    Route::middleware('auth')->controller(DashboardController::class)->group(function () {
        Route::get('dashboard', 'dashboard')->name('dashboard');
        Route::get(
            '/',
            'dashboard'
        );
    });
    Route::middleware('auth')->controller(ApiController::class)->group(function () {

        Route::get('config', 'config')->name('config');
        Route::get('logs', 'index')->name('index');
    });

    Route::middleware('auth')->prefix('queue')->group(function () {

        Route::get('/clear/sutran', function () {
            $output = QueueManager::clearSutranQueue();
            return response()->json([
                'success' => true,
                'message' => 'Cola Sutran limpiada exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.clear.sutran');

        Route::get('/clear/web-services', function () {
            $output = QueueManager::clearWebServicesQueue();
            return response()->json([
                'success' => true,
                'message' => 'Cola de servicios web limpiada exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.clear.web-services');

        Route::get('/clear/historial', function () {
            $output = QueueManager::clearHistorialQueue();
            return response()->json([
                'success' => true,
                'message' => 'Cola de reenvío de historial limpiada exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.clear.historial');

        Route::get('/clear/all', function () {
            $output = QueueManager::clearAllQueues();
            return response()->json([
                'success' => true,
                'message' => 'Todas las colas limpiadas exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.clear.all');

        Route::get('/restart', function () {
            $output = QueueManager::restartQueueWorkers();
            return response()->json([
                'success' => true,
                'message' => 'Workers de cola reiniciados exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.restart');

        Route::get('/flush', function () {
            $output = QueueManager::clearFailedJobs();
            return response()->json([
                'success' => true,
                'message' => 'Jobs fallidos eliminados exitosamente',
                'output' => $output,
                'timestamp' => now()
            ]);
        })->name('queue.flush');

        Route::get('/info', function () {
            $info = QueueManager::getQueueInfo();
            return response()->json($info);
        })->name('queue.info');
    });
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');
    Route::get('ajustes/cuenta', [AjustesController::class, 'cuenta'])->name('ajustes.cuenta');
});
