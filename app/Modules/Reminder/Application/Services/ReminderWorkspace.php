<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Models\User;
use App\Modules\Agent\Application\Contracts\AgentAccessScopeReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\BusinessGroupReferenceReader;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Customer\Application\Data\ReminderCustomerData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final readonly class ReminderWorkspace
{
    public function __construct(
        private ReminderCustomerReader $customers,
        private InternalUserReferenceReader $users,
        private ReminderContentPresenter $content,
        private BusinessClock $clock,
        private AccessContextResolver $access,
        private AgentAccessScopeReader $agentScope,
        private BusinessGroupReferenceReader $groups,
    ) {}

    /** @return array<int, ReminderCustomerData> */
    public function customerCandidates(): array
    {
        return $this->customers->candidates();
    }

    /** @return array<int, string> */
    public function customerNames(): array
    {
        return collect($this->customers->candidates())->pluck('name', 'id')->all();
    }

    /** @return list<array{id: int, name: string}> */
    public function assigneeCandidates(): array
    {
        $users = $this->users->eligibleUsers();
        $context = $this->access->current();
        if ($context->isSuperAdmin()) {
            return $users;
        }
        if (! $context->hasEffectiveBusinessScope()) {
            return [];
        }
        $allowed = [...$context->groupUserIds, $context->userId];

        return array_values(array_filter($users, fn (array $user): bool => in_array((int) $user['id'], $allowed, true)));
    }

    public function isEligibleAssignee(int $id): bool
    {
        return collect($this->assigneeCandidates())->contains(fn (array $user): bool => (int) $user['id'] === $id);
    }

    /** @return LengthAwarePaginator<int, Reminder> */
    public function paginate(User $user, bool $history, ?string $type = null, bool $overdueOnly = false, ?int $businessGroupId = null): LengthAwarePaginator
    {
        $customerIds = $businessGroupId === null ? null : $this->customerIdsForBusinessGroup($businessGroupId);
        /** @var LengthAwarePaginator<int, Reminder> $page */
        $page = $this->visible(Reminder::query(), $user)
            ->when($history, fn (Builder $query) => $query->whereIn('status', ['completed', 'cancelled']))
            ->when(! $history, fn (Builder $query) => $query->whereIn('status', ['pending', 'snoozed', 'transferred']))
            ->when($type !== null && $type !== '', fn (Builder $query) => $query->where('reminder_type', $type))
            ->when($overdueOnly && ! $history, fn (Builder $query) => $query->where('due_at', '<', $this->clock->now()))
            ->when($customerIds !== null && $customerIds === [], fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($customerIds !== null && $customerIds !== [], fn (Builder $query) => $query->whereIn('customer_id', $customerIds))
            ->orderBy('due_at')
            ->orderBy('priority')
            ->orderBy('id')
            ->paginate(30);
        $page->setCollection($page->getCollection()->map(
            fn (Reminder $reminder): Reminder => $this->content->applyToReminder($reminder),
        ));

        return $page;
    }

    /** @return list<int> */
    private function customerIdsForBusinessGroup(int $businessGroupId): array
    {
        $context = $this->access->current();
        abort_unless(
            $context->isSuperAdmin()
                ? $this->groups->exists($businessGroupId, true)
                : in_array($businessGroupId, $context->businessGroupIds, true),
            404,
        );
        $agentIds = $this->agentScope->agentIdsForBusinessGroups([$businessGroupId], $this->clock->now()->toDateString());
        if ($agentIds === []) {
            return [];
        }

        return collect($this->customers->candidates())
            ->filter(fn (ReminderCustomerData $customer): bool => $customer->sourceAgentId !== null && in_array($customer->sourceAgentId, $agentIds, true))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $recurrence */
    public function createCustom(
        int $customerId,
        int $assignedTo,
        string $title,
        CarbonImmutable $dueAt,
        ?string $notes,
        ?string $suggestion,
        ?array $recurrence,
        ?int $templateId,
        int $actorId,
    ): int {
        $this->customers->byId($customerId);
        if (! $this->isEligibleAssignee($assignedTo)) {
            throw new DomainException(__('reminders.errors.assignee_unavailable'));
        }
        if ($dueAt->isBefore($this->clock->now())) {
            throw new DomainException(__('reminders.errors.custom_due_past'));
        }
        if ($recurrence !== null && ! in_array($recurrence['unit'] ?? null, ['day', 'week', 'month'], true)) {
            throw new DomainException(__('reminders.errors.invalid_recurrence_unit'));
        }
        $template = $templateId === null ? null : ReminderTemplate::query()->where('is_active', true)->findOrFail($templateId);
        $dedupe = hash('sha256', implode(':', ['custom', $actorId, $customerId, $dueAt->toIso8601String(), trim($title)]));
        $reminder = Reminder::query()->create([
            'template_id' => $template?->id,
            'customer_id' => $customerId,
            'assigned_to' => $assignedTo,
            'created_by' => $actorId,
            'source_type' => 'custom',
            'reminder_type' => 'custom',
            'title' => trim($title),
            'suggestion' => $this->nullable($suggestion),
            'notes' => $this->nullable($notes),
            'priority' => 3,
            'due_at' => $dueAt,
            'recurrence' => $recurrence,
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => $dedupe,
        ]);
        $this->event($reminder, 'generated', $actorId, ['source' => 'custom']);

        return (int) $reminder->id;
    }

    public function complete(int $id, User $actor, ?string $notes): void
    {
        DB::transaction(function () use ($id, $actor, $notes): void {
            $reminder = $this->findVisible($id, $actor);
            if (! in_array($reminder->status, ['pending', 'snoozed', 'transferred'], true)) {
                throw new DomainException(__('reminders.errors.invalid_completion_status'));
            }
            $reminder->update([
                'status' => 'completed',
                'completed_at' => $this->clock->now(),
                'completed_by' => $actor->id,
                'notes' => $this->nullable($notes) ?? $reminder->notes,
            ]);
            $this->event($reminder, 'completed', (int) $actor->id, ['notes' => $this->nullable($notes)]);
            $this->createNextRecurrence($reminder, (int) $actor->id);
        });
    }

    public function snooze(int $id, CarbonImmutable $until, string $reason, User $actor): void
    {
        if (trim($reason) === '' || $until->isBefore($this->clock->now())) {
            throw new DomainException(__('reminders.errors.snooze_reason_and_future'));
        }
        $reminder = $this->findVisible($id, $actor);
        $before = $reminder->due_at->toIso8601String();
        $reminder->update(['status' => 'snoozed', 'due_at' => $until, 'notification_status' => 'pending']);
        $this->event($reminder, 'snoozed', (int) $actor->id, ['before' => $before, 'after' => $until->toIso8601String(), 'reason' => trim($reason)]);
    }

    public function transfer(int $id, int $assigneeId, User $actor): void
    {
        if (! $this->isEligibleAssignee($assigneeId)) {
            throw new DomainException(__('reminders.errors.assignee_unavailable'));
        }
        $reminder = $this->findVisible($id, $actor);
        $before = $reminder->assigned_to;
        $reminder->update(['status' => 'transferred', 'assigned_to' => $assigneeId, 'notification_status' => 'pending']);
        $this->event($reminder, 'transferred', (int) $actor->id, ['before' => $before, 'after' => $assigneeId]);
    }

    public function cancel(int $id, string $reason, User $actor): void
    {
        if (trim($reason) === '') {
            throw new DomainException(__('reminders.errors.cancel_reason_required'));
        }
        $reminder = $this->findVisible($id, $actor);
        $reminder->update(['status' => 'cancelled']);
        $this->event($reminder, 'cancelled', (int) $actor->id, ['reason' => trim($reason)]);
    }

    /** @return array<string, int> */
    public function completionStats(User $user): array
    {
        $visible = fn () => $this->visible(Reminder::query(), $user);

        return [
            'completed' => $visible()->where('status', 'completed')->count(),
            'pending' => $visible()->whereIn('status', ['pending', 'snoozed', 'transferred'])->count(),
            'overdue' => $visible()->whereIn('status', ['pending', 'snoozed', 'transferred'])->where('due_at', '<', $this->clock->now())->count(),
        ];
    }

    private function findVisible(int $id, User $user): Reminder
    {
        /** @var Reminder $reminder */
        $reminder = $this->visible(Reminder::query(), $user)->findOrFail($id);

        return $reminder;
    }

    /**
     * @param  Builder<Reminder>  $query
     * @return Builder<Reminder>
     */
    private function visible(Builder $query, User $user): Builder
    {
        $context = $this->access->forUser($user);
        if ($context->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user, $context): void {
            $inner->where('assigned_to', $user->id)->orWhere('created_by', $user->id);
            if ($context->isBdManager()) {
                $customerIds = $this->access->using($context, fn (): array => collect($this->customers->candidates())
                    ->pluck('id')->map(fn ($id): int => (int) $id)->all());
                if ($customerIds !== []) {
                    $inner->orWhereIn('customer_id', $customerIds);
                }
            }
        });
    }

    /** @param array<string, mixed> $properties */
    private function event(Reminder $reminder, string $event, ?int $actorId, array $properties): void
    {
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'event' => $event,
            'actor_id' => $actorId,
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    private function createNextRecurrence(Reminder $reminder, int $actorId): void
    {
        if ($reminder->recurrence === null) {
            return;
        }
        $interval = max(1, (int) ($reminder->recurrence['interval'] ?? 1));
        $next = match ($reminder->recurrence['unit'] ?? null) {
            'day' => CarbonImmutable::parse($reminder->due_at)->addDays($interval),
            'week' => CarbonImmutable::parse($reminder->due_at)->addWeeks($interval),
            'month' => CarbonImmutable::parse($reminder->due_at)->addMonthsNoOverflow($interval),
            default => null,
        };
        if ($next === null) {
            return;
        }
        $copy = $reminder->replicate(['status', 'notification_status', 'notified_at', 'completed_at', 'completed_by', 'dedupe_key']);
        $copy->fill([
            'due_at' => $next,
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => hash('sha256', "recurrence:{$reminder->id}:{$next->toIso8601String()}"),
        ])->save();
        $this->event($copy, 'generated', $actorId, ['source' => 'recurrence', 'previous_reminder_id' => $reminder->id]);
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
