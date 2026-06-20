<?php

namespace App\Jobs;

use App\Models\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\Senders\SatelTrackSender;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\Transformers\UnitTransformer;
use App\Services\Formatters\SatelTrackFormatter;

class SendToSatelTrackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $units;

    public function __construct(array $units)
    {
        $this->units = $units;
        $this->onQueue('web-services-sateltrack');
    }

    public function handle()
    {
        $transformer = new UnitTransformer();
        $formatter = new SatelTrackFormatter($transformer);

        $tramas = $formatter->format($this->units);

        $url = Config::first()->servicios['sateltrack']['url'] ?? null;

        if (empty($url)) {
            Log::channel('sateltrack')->error('URL de SatelTrack no configurada; no se envían tramas', [
                'total_tramas' => count($tramas),
            ]);
            return;
        }

        $this->chunkTramas($tramas, $url);
    }

    /**
     * Tracklog acepta un máximo de 200 registros por envío.
     */
    public function chunkTramas(array $tramas, string $url): void
    {
        $grupos = array_chunk($tramas, 200);

        foreach ($grupos as $grupo) {
            $sender = new SatelTrackSender();
            $sender->send($grupo, $url);
        }
    }
}
