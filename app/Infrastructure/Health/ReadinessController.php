<?php

namespace App\Infrastructure\Health;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadinessController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            Redis::connection()->ping();

            return response()->json(['status' => 'ok']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(
                ['status' => 'unavailable'],
                JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }
}
