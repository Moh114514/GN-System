<?php

namespace App\Modules\Audit\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;

final class SpatieAuditRecorder implements AuditRecorder
{
    public function record(string $description, array $properties = [], ?int $causerId = null): void
    {
        $logger = activity('data-import')->withProperties($properties);

        if ($causerId !== null) {
            $causer = User::query()->find($causerId);
            if ($causer !== null) {
                $logger->causedBy($causer);
            }
        }

        $logger->log($description);
    }
}
