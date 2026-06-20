<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\ProcessSatelTrackAlarmsJob;

class SatelTrackAlarmsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sateltrack:alarms';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consulta y reenvía las alarmas de conducción (134/135/143) a SatelTrack';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        ProcessSatelTrackAlarmsJob::dispatch();
    }
}
