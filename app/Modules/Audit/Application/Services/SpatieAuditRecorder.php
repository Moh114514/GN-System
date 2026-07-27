<?php

namespace App\Modules\Audit\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

final class SpatieAuditRecorder implements AuditRecorder
{
    public function record(
        string $description,
        array $properties = [],
        ?int $causerId = null,
        ?Model $subject = null,
        string $logName = 'data-import',
        ?string $event = null,
        ?string $ipAddress = null,
    ): void {
        if ($ipAddress !== null) {
            $properties['ip_address'] = $ipAddress;
        }

        $logger = activity($logName)->withProperties($properties);

        if ($causerId !== null) {
            $causer = User::query()->find($causerId);
            if ($causer !== null) {
                $logger->causedBy($causer);
            }
        }

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($event !== null) {
            $logger->event($event);
        }

        $logger->log($description);
    }

    public function trail(Model $subject, string $logName): array
    {
        return Activity::query()
            ->where('log_name', $logName)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Activity $activity): AuditEntryData => new AuditEntryData(
                description: (string) $activity->description,
                event: $activity->event,
                properties: $activity->properties->all(),
                causerId: $activity->causer_id === null ? null : (int) $activity->causer_id,
                occurredAt: CarbonImmutable::instance($activity->created_at),
            ))
            ->all();
    }
}
