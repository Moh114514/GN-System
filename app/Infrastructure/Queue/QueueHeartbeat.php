<?php

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::put(
            'gn-system:queue-heartbeat',
            now()->toIso8601String(),
            now()->addMinutes(5),
        );
    }
}
