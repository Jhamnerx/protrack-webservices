<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UtilesController;
use App\Http\Controllers\Api\SelectsController;
use App\Http\Controllers\ApiController;

// Rutas para el servicio de Protrack API
Route::prefix('protrack')->group(function () {
    Route::post('/playback', [ApiController::class, 'getPlayback'])
        ->name('api.protrack.playback');

    Route::post('/playback/last24h', [ApiController::class, 'getPlaybackLast24h'])
        ->name('api.protrack.playback.last24h');
});





// Route::get('/print-receipt/{venta}', [PrintController::class, 'printReceipt'])->name('api.print.receipt');
// Route::get('/print-receipt/devolucion/{devolucion}', [PrintController::class, 'printReceiptDevolucion'])->name('api.print.receipt.devolucion');
// Route::get('/print-receipt-test', [PrintController::class, 'test'])->name('api.print.test');
