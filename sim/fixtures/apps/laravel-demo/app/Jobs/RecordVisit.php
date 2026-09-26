<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class RecordVisit implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $at) {}

    public function handle(): void
    {
        Log::info('Recorded visit', ['at' => $this->at]);
    }
}
