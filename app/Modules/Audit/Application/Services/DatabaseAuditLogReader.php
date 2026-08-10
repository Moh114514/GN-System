<?php

namespace App\Modules\Audit\Application\Services;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditLogReader;
use App\Modules\Audit\Application\Data\AuditLogEntryData;
use App\Modules\Audit\Application\Data\AuditLogFilterData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Lang;
use Spatie\Activitylog\Models\Activity;

final class DatabaseAuditLogReader implements AuditLogReader
{
    public function __construct(private readonly AuditMessageCatalog $messages) {}

    /** @var array<int, string> */
    private const SAFE_SCALAR_PROPERTIES = [
        'user_id', 'role', 'invitation_status', 'code', 'automatic_code', 'policy_grade_id',
        'next_policy_grade_id', 'target_snapshot_id', 'stage_count', 'status_count', 'followup_id',
        'type', 'followed_up_on', 'source', 'due_at', 'status', 'settlement_id', 'channel',
        'agent_id', 'direct_sales_source_id', 'institution_id', 'customer_id', 'amount_krw',
        'rate_bps', 'effective_month', 'effective_from', 'effective_until', 'completed_on',
        'completion_precision', 'ip_address', 'reason', 'exchange_rate_krw_per_cny',
        'exchange_rate_quote_source', 'exchange_rate_quoted_at', 'exchange_rate_manual_override',
        'import_batch_id', 'operation',
    ];

    /** @var array<int, string> */
    private const SAFE_ATTRIBUTE_PROPERTIES = [
        'id', 'code', 'agent_type_code_id', 'cooperation_status', 'policy_grade_id', 'rate_bps',
        'effective_month', 'effective_from', 'effective_until', 'is_active', 'status', 'channel',
        'agent_id', 'direct_sales_source_id', 'institution_id', 'customer_id', 'amount_krw',
        'completed_on', 'completed_at', 'completion_precision', 'created_at', 'updated_at',
    ];

    public function paginate(AuditLogFilterData $filters, int $perPage): LengthAwarePaginator
    {
        return Activity::query()
            ->with(['causer' => fn ($query) => $query->select('id', 'name')])
            ->when($filters->occurredOn !== null, function (Builder $query) use ($filters): void {
                $date = CarbonImmutable::parse($filters->occurredOn, 'Asia/Shanghai');
                $query->whereBetween('created_at', [$date->startOfDay(), $date->endOfDay()]);
            })
            ->when($filters->causerId !== null, fn (Builder $query) => $query->where('causer_id', $filters->causerId))
            ->when($filters->targetUserId !== null, function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    $query->where(function (Builder $query) use ($filters): void {
                        $query->where('subject_type', (new User)->getMorphClass())
                            ->where('subject_id', $filters->targetUserId);
                    })->orWhereJsonContains('properties->user_id', $filters->targetUserId);
                });
            })
            ->when($filters->module !== null, fn (Builder $query) => $query->where('log_name', $filters->module))
            ->when($filters->action !== null, fn (Builder $query) => $query->where('event', $filters->action))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->through(fn (Activity $activity): AuditLogEntryData => $this->entry($activity));
    }

    public function filterOptions(): array
    {
        return [
            'users' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
            ])->all(),
            'modules' => Activity::query()->whereNotNull('log_name')->distinct()->orderBy('log_name')->pluck('log_name')->all(),
            'actions' => Activity::query()->whereNotNull('event')->distinct()->orderBy('event')->pluck('event')->all(),
        ];
    }

    private function entry(Activity $activity): AuditLogEntryData
    {
        $properties = $activity->properties->all();
        $targetUserId = $activity->subject_type === (new User)->getMorphClass()
            ? (int) $activity->subject_id
            : (isset($properties['user_id']) && is_numeric($properties['user_id']) ? (int) $properties['user_id'] : null);

        $messageKey = $this->messageKey($activity, $properties);

        return new AuditLogEntryData(
            id: (int) $activity->id,
            occurredAt: CarbonImmutable::instance($activity->created_at),
            module: (string) ($activity->log_name ?? 'system'),
            action: (string) ($activity->event ?? 'recorded'),
            description: $this->localizedDescription($activity, $properties, $messageKey),
            causerName: $activity->causer instanceof User ? $activity->causer->name : null,
            targetUserId: $targetUserId,
            properties: $this->safeProperties($properties),
            legacyDescription: $messageKey === null,
        );
    }

    /** @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function safeProperties(array $properties): array
    {
        $safe = [];

        foreach ($properties as $key => $value) {
            if ($key === 'before' || $key === 'after') {
                if (is_array($value)) {
                    $safe[$key] = $this->safeAttributes($value);
                }

                continue;
            }

            if (! in_array($key, self::SAFE_SCALAR_PROPERTIES, true) || is_array($value) || is_object($value)) {
                continue;
            }

            $safe[$key] = $key === 'ip_address' && is_string($value) ? $this->maskIpAddress($value) : $value;
        }

        return $safe;
    }

    /** @param array<string, mixed> $properties */
    private function localizedDescription(Activity $activity, array $properties, ?string $messageKey = null): string
    {
        $messageKey ??= $this->messageKey($activity, $properties);
        $parameters = $properties['message_parameters'] ?? [];

        if (is_string($messageKey) && Lang::has($messageKey)) {
            return (string) __($messageKey, is_array($parameters) ? $parameters : []);
        }

        return (string) $activity->description;
    }

    /** @param array<string, mixed> $properties */
    private function messageKey(Activity $activity, array $properties): ?string
    {
        $messageKey = $properties['message_key'] ?? null;

        return is_string($messageKey)
            ? $messageKey
            : $this->messages->keyFor((string) $activity->description);
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        return collect($attributes)
            ->only(self::SAFE_ATTRIBUTE_PROPERTIES)
            ->filter(fn (mixed $value): bool => ! is_array($value) && ! is_object($value))
            ->all();
    }

    private function maskIpAddress(string $ipAddress): string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\\.\\d+$/', '.***', $ipAddress) ?? '***';
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return implode(':', array_slice(explode(':', $ipAddress), 0, 3)).':***';
        }

        return '***';
    }
}
