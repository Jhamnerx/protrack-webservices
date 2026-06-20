<?php

use App\Jobs\CheckDevicesExists;
use App\Jobs\ClearQueues;
use Laravel\Prompts\Clear;
use Illuminate\Http\Request;
use App\Jobs\ClearLogs;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Permission\Middleware\RoleMiddleware;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function () {
            //Route::middleware('web', 'auth')->prefix('admin')->group(base_path('routes/admin.php'));
        },
    )->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,

        ]);
    })->withSchedule(function (Schedule $schedule) {

        $schedule->command('api:get')->everyThirtySeconds()->runInBackground();
        $schedule->command('sateltrack:alarms')->everyMinute()->withoutOverlapping()->runInBackground();
        $schedule->command('protrack:refresh-token')->hourly()->runInBackground();
        $schedule->job(new ClearLogs(3))->daily();

        // Limpiar colas todos los días a las 8:00 PM
        $schedule->job(new ClearQueues())->dailyAt('20:00');
    })
    ->withExceptions(function (Exceptions $exceptions) {})->create();
