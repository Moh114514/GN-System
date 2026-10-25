<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Reminder\Application\Contracts\CustomerFollowupGateway;
use App\Modules\Reminder\Application\Data\CustomerFollowupData;
use App\Modules\Reminder\Infrastructure\Models\FollowupRecord;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DatabaseCustomerFollowupGateway implements CustomerFollowupGateway
{
    public function __construct(private readonly NotificationRecipientGateway $notifications) {}

    public function record(CustomerFollowupData $data): int
    {
        return DB::transaction(function () use ($data): int {
            $reminder = $data->reminderId === null
                ? null
                : Reminder::query()->where('customer_id', $data->customerId)->lockForUpdate()->findOrFail($data->reminderId);

            if ($reminder !== null && ($existing = FollowupRecord::query()->where('reminder_id', $reminder->id)->first()) !== null) {
                throw new \DomainException(__('reminders.errors.followup_task_already_recorded'));
            }

            if ($reminder !== null && ! in_array($reminder->status, ['pending', 'snoozed', 'transferred'], true)) {
                throw new \DomainException(__('reminders.errors.followup_task_not_open'));
            }

            $followup = FollowupRecord::query()->create([
                'customer_id' => $data->customerId,
                'type' => $data->type,
                'followed_up_on' => $data->followedUpOn,
                'content' => $data->content,
                'result' => $data->result,
                'owner_id' => $data->ownerId,
                'reminder_id' => $reminder?->id,
            ]);

            if ($reminder !== null) {
                if ($reminder->assigned_to !== null) {
                    $this->notifications->markReminderNotificationReadForUser((int) $reminder->id, (int) $reminder->assigned_to);
                }
                $reminder->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'completed_by' => $data->ownerId,
                    'notes' => $this->appendNote($reminder->notes, $data->result),
                ]);
                $this->event($reminder, 'completed', $data->ownerId, ['source' => 'customer_followup', 'followup_id' => $followup->id]);
            }

            if ($data->nextFollowupAt !== null) {
                $next = Reminder::query()->create([
                    'customer_id' => $data->customerId,
                    'assigned_to' => $data->ownerId,
                    'created_by' => $data->ownerId,
                    'source_type' => 'custom',
                    'reminder_type' => 'custom',
                    'title' => __('reminders.followup.next_title'),
                    'suggestion' => null,
                    'notes' => null,
                    'priority' => 3,
                    'due_at' => $data->nextFollowupAt,
                    'recurrence' => null,
                    'status' => 'pending',
                    'notification_status' => 'pending',
                    'dedupe_key' => hash('sha256', 'customer-followup:'.$followup->id),
                ]);
                $this->event($next, 'generated', $data->ownerId, ['source' => 'customer_followup', 'followup_id' => $followup->id]);
            }

            return (int) $followup->id;
        }, 3);
    }

    public function timelineForCustomer(int $customerId): array
    {
        return FollowupRecord::query()
            ->where('customer_id', $customerId)
            ->orderByDesc('followed_up_on')
            ->get()
            ->map(fn (FollowupRecord $record): array => [
                'type' => 'followup',
                'occurred_at' => $record->followed_up_on === null
                    ? $record->created_at?->toIso8601String()
                    : CarbonImmutable::parse($record->followed_up_on)->startOfDay()->toIso8601String(),
                'title' => __('reminders.timeline.customer_followup'),
                'content' => $record->content,
                'owner_id' => $record->owner_id === null ? null : (int) $record->owner_id,
                'meta' => ['followup_type' => $record->type, 'result' => $record->result],
            ])
            ->all();
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array{last_followup_on: string|null, last_followup_content: string|null, next_followup_at: string|null, followup_status: string}>
     */
    public function summariesForCustomers(array $customerIds, CarbonImmutable $asOf): array
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));
        if ($customerIds === []) {
            return [];
        }

        $summaries = array_fill_keys($customerIds, [
            'last_followup_on' => null,
            'last_followup_content' => null,
            'next_followup_at' => null,
            'followup_status' => 'none',
        ]);
        FollowupRecord::query()->whereIn('customer_id', $customerIds)->orderByDesc('followed_up_on')->orderByDesc('id')->get()
            ->unique('customer_id')->each(function (FollowupRecord $record) use (&$summaries): void {
                $summary = $summaries[(int) $record->customer_id];
                $summary['last_followup_on'] = $record->followed_up_on === null
                    ? null
                    : CarbonImmutable::parse($record->followed_up_on)->format('Y-m-d');
                $summary['last_followup_content'] = $record->content;
                $summaries[(int) $record->customer_id] = $summary;
            });

        foreach ($this->earliestActiveReminderByCustomer($customerIds) as $customerId => $dueAt) {
            $summary = $summaries[(int) $customerId];
            $summary['next_followup_at'] = $dueAt->toIso8601String();
            $summary['followup_status'] = $this->statusForDueAt($dueAt, $asOf);
            $summaries[(int) $customerId] = $summary;
        }

        return $summaries;
    }

    public function customerIdsWithFollowupStatus(string $status, CarbonImmutable $asOf): array
    {
        if (! in_array($status, ['pending', 'today', 'upcoming', 'overdue', 'none'], true)) {
            throw new \InvalidArgumentException('Unsupported customer follow-up status.');
        }
        $dueByCustomer = $this->earliestActiveReminderByCustomer(null);
        $ids = [];
        foreach ($dueByCustomer as $customerId => $dueAt) {
            $category = $this->statusForDueAt($dueAt, $asOf);
            if ($status === 'pending' || $category === $status) {
                $ids[] = (int) $customerId;
            }
        }

        return $ids;
    }

    /**
     * @param  list<int>  $customerIds
     * @return array{today: int, overdue: int, upcoming: int}
     */
    public function statusCountsForCustomers(array $customerIds, CarbonImmutable $asOf): array
    {
        $counts = ['today' => 0, 'overdue' => 0, 'upcoming' => 0];
        foreach ($this->earliestActiveReminderByCustomer($customerIds) as $dueAt) {
            $category = $this->statusForDueAt($dueAt, $asOf);
            if (isset($counts[$category])) {
                $counts[$category]++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>|null  $customerIds
     * @return array<int, CarbonImmutable>
     */
    private function earliestActiveReminderByCustomer(?array $customerIds): array
    {
        $query = Reminder::query()->whereIn('status', ['pending', 'snoozed', 'transferred']);
        if ($customerIds !== null) {
            if ($customerIds === []) {
                return [];
            }
            $query->whereIn('customer_id', $customerIds);
        }

        return $query->selectRaw('customer_id, MIN(due_at) as next_due_at')
            ->groupBy('customer_id')->get()->mapWithKeys(fn (Reminder $reminder): array => [
                (int) $reminder->customer_id => CarbonImmutable::parse((string) $reminder->getAttribute('next_due_at')),
            ])->all();
    }

    private function statusForDueAt(CarbonImmutable $dueAt, CarbonImmutable $asOf): string
    {
        if ($dueAt->lessThan($asOf)) {
            return 'overdue';
        }
        if ($dueAt->isSameDay($asOf)) {
            return 'today';
        }
        if ($dueAt->lessThanOrEqualTo($asOf->startOfDay()->addDays(3)->endOfDay())) {
            return 'upcoming';
        }

        return 'pending';
    }

    /** @param array<string, mixed> $properties */
    private function event(Reminder $reminder, string $event, int $actorId, array $properties): void
    {
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'event' => $event,
            'actor_id' => $actorId,
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    private function appendNote(?string $existing, string $result): ?string
    {
        $note = trim(implode("\n\n", array_filter([trim((string) $existing), trim($result)])));

        return $note === '' ? null : $note;
    }
}
