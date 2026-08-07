<?php

namespace App\Modules\Audit\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Audit\Application\Data\AuditEntryData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;

final class SpatieAuditRecorder implements AuditRecorder
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $messageParameters
     */
    public function record(
        string $description,
        array $properties = [],
        ?int $causerId = null,
        ?Model $subject = null,
        string $logName = 'data-import',
        ?string $event = null,
        ?string $ipAddress = null,
        ?string $messageKey = null,
        array $messageParameters = [],
    ): void {
        if ($messageKey !== null) {
            $properties['message_key'] = $messageKey;
            $properties['message_parameters'] = $messageParameters;
        }

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
            ->map(function (Activity $activity): AuditEntryData {
                $properties = $activity->properties->all();

                return new AuditEntryData(
                    description: $this->localizedDescription($activity, $properties),
                    event: $activity->event,
                    properties: $properties,
                    causerId: $activity->causer_id === null ? null : (int) $activity->causer_id,
                    occurredAt: CarbonImmutable::instance($activity->created_at),
                );
            })
            ->all();
    }

    /** @param array<string, mixed> $properties */
    private function localizedDescription(Activity $activity, array $properties): string
    {
        $messageKey = $properties['message_key'] ?? null;
        $parameters = $properties['message_parameters'] ?? [];

        if (is_string($messageKey) && Lang::has($messageKey)) {
            return (string) __($messageKey, is_array($parameters) ? $parameters : []);
        }

        return (string) $activity->description;
    }
}
