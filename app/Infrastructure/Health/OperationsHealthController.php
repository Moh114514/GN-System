<?php

namespace App\Infrastructure\Health;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class OperationsHealthController
{
    private const MAX_HEARTBEAT_AGE_MINUTES = 3;

    public function __invoke(): JsonResponse
    {
        try {
            $components = [
                'queue' => $this->heartbeatStatus('gn-system:queue-heartbeat'),
                'scheduler' => $this->heartbeatStatus('gn-system:scheduler-heartbeat'),
            ];

            $healthy = ! in_array('unavailable', $components, true);

            return response()->json(
                [
                    'status' => $healthy ? 'ok' : 'unavailable',
                    'components' => $components,
                ],
                $healthy ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                [
                    'status' => 'unavailable',
                    'components' => [
                        'queue' => 'unavailable',
                        'scheduler' => 'unavailable',
                    ],
                ],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    private function heartbeatStatus(string $key): string
    {
        $heartbeat = Cache::get($key);

        if (! is_string($heartbeat)) {
            return 'unavailable';
        }

        $recordedAt = CarbonImmutable::parse($heartbeat);

        return $recordedAt->greaterThanOrEqualTo(
            now()->subMinutes(self::MAX_HEARTBEAT_AGE_MINUTES),
        ) ? 'ok' : 'unavailable';
    }
}
