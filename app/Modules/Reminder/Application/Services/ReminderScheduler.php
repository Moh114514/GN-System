<?php

namespace App\Modules\Reminder\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Customer\Application\Contracts\ReminderCustomerReader;
use App\Modules\Customer\Application\Data\ReminderCustomerData;
use App\Modules\Order\Application\Contracts\ReminderSourceReader;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use Carbon\CarbonImmutable;

final readonly class ReminderScheduler
{
    public function __construct(
        private ReminderCustomerReader $customers,
        private ReminderSourceReader $sources,
        private TreatmentReminderGateway $treatments,
        private BusinessClock $clock,
    ) {}

    public function materialize(?CarbonImmutable $at = null): int
    {
        $now = $at ?? $this->clock->now();
        $created = $this->appointments($now);
        foreach ($this->sources->completedOrders() as $order) {
            $before = Reminder::query()->count();
            $this->treatments->schedule(new CompletedTreatmentData(
                orderId: $order->id,
                customerId: $order->customerId,
                projectName: $order->projectName,
                completedOn: $order->completedOn,
                ownerId: $order->ownerId,
                actorId: $order->ownerId,
            ));
            $created += Reminder::query()->count() - $before;
        }

        $appointmentByCustomer = collect($this->sources->appointments())
            ->where('status', 'scheduled')
            ->keyBy('customerId');
        $orderByCustomer = collect($this->sources->completedOrders())->sortByDesc('completedOn')->keyBy('customerId');
        foreach (ReminderRule::query()->where('is_active', true)->get() as $rule) {
            if ($rule->trigger_type === 'manual') {
                continue;
            }
            foreach ($this->customers->candidates() as $customer) {
                if (! $this->inScope($rule, $customer)) {
                    continue;
                }
                $dueAt = $this->ruleDueAt(
                    $rule,
                    $customer,
                    $appointmentByCustomer->get($customer->id),
                    $orderByCustomer->get($customer->id),
                    $now,
                );
                if ($dueAt === null || $dueAt->isBefore($now->subDay()) || $dueAt->isAfter($now->addDays(200))) {
                    continue;
                }
                $created += $this->create(
                    customerId: $customer->id,
                    assignedTo: $customer->ownerId,
                    dueAt: $dueAt,
                    sourceType: 'rule',
                    reminderType: $rule->trigger_type,
                    title: (string) $rule->title,
                    suggestion: $rule->suggestion,
                    priority: (int) $rule->priority,
                    dedupeSource: $rule->trigger_type === 'holiday_date'
                        ? "holiday-rule:{$rule->id}:{$customer->id}:{$rule->trigger_config['date']}"
                        : "rule:{$rule->id}:customer:{$customer->id}:{$dueAt->toIso8601String()}",
                    ruleId: (int) $rule->id,
                );
            }
        }

        return $created;
    }

    private function appointments(CarbonImmutable $now): int
    {
        $created = 0;
        foreach ($this->sources->appointments() as $appointment) {
            if ($appointment->status !== 'scheduled') {
                $cancelled = Reminder::query()
                    ->where('appointment_id', $appointment->id)
                    ->whereIn('status', ['pending', 'snoozed', 'transferred'])
                    ->get();
                foreach ($cancelled as $reminder) {
                    $before = $reminder->status;
                    $reminder->update(['status' => 'cancelled']);
                    ReminderEvent::query()->create([
                        'reminder_id' => $reminder->id,
                        'event' => 'cancelled',
                        'properties' => [
                            'reason_key' => 'reminders.events.appointment_cancelled',
                            'before' => $before,
                            'after' => 'cancelled',
                        ],
                        'occurred_at' => now(),
                    ]);
                }

                continue;
            }
            foreach ([
                [-3, '09:00', 'pre_visit_3_days'],
                [-1, '18:00', 'arrival_previous_day'],
                [0, '09:00', 'arrival_today'],
            ] as [$days, $time, $contentKey]) {
                $dueAt = $appointment->scheduledAt->startOfDay()->addDays($days)->setTimeFromTimeString($time);
                if ($dueAt->isBefore($now->startOfDay()) || $dueAt->isAfter($now->addDays(200))) {
                    continue;
                }
                $created += $this->create(
                    customerId: $appointment->customerId,
                    assignedTo: $appointment->ownerId,
                    dueAt: $dueAt,
                    sourceType: 'system',
                    reminderType: 'appointment',
                    title: (string) __("reminders.system_reminders.{$contentKey}.title"),
                    suggestion: (string) __("reminders.system_reminders.{$contentKey}.suggestion"),
                    priority: 1,
                    dedupeSource: "appointment:{$appointment->id}:{$days}:{$time}",
                    appointmentId: $appointment->id,
                    localizedContent: [
                        'title' => ['key' => "reminders.system_reminders.{$contentKey}.title", 'parameters' => []],
                        'suggestion' => ['key' => "reminders.system_reminders.{$contentKey}.suggestion", 'parameters' => []],
                    ],
                );
            }
        }

        return $created;
    }

    private function inScope(ReminderRule $rule, ReminderCustomerData $customer): bool
    {
        $value = $rule->scope_config['value'] ?? null;

        return match ($rule->scope_type) {
            'all_customers' => true,
            'agent' => $customer->sourceAgentId === (int) $value,
            'project' => mb_strtolower((string) $customer->projectIntention) === mb_strtolower((string) $value),
            'owner' => $customer->ownerId === (int) $value,
            'cooperation_status' => $customer->agentStatus === (string) $value,
            default => false,
        };
    }

    private function ruleDueAt(
        ReminderRule $rule,
        ReminderCustomerData $customer,
        mixed $appointment,
        mixed $order,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        $config = $rule->trigger_config;
        $time = (string) ($config['time'] ?? '09:00');
        if ($rule->trigger_type === 'status_change') {
            if (isset($config['status_id']) && (int) $config['status_id'] !== $customer->statusId) {
                return null;
            }

            return $customer->statusChangedAt?->addDays((int) ($config['offset_days'] ?? 0))->setTimeFromTimeString($time);
        }
        if ($rule->trigger_type === 'fixed_cycle') {
            $days = (int) ($config['interval_days'] ?? 0);
            if ($days < 1) {
                return null;
            }
            $elapsed = max(0, $customer->createdAt->startOfDay()->diffInDays($now->startOfDay()));
            $cycles = intdiv($elapsed, $days);
            if ($elapsed === 0 || $elapsed % $days !== 0) {
                $cycles++;
            }

            return $customer->createdAt->startOfDay()->addDays($cycles * $days)->setTimeFromTimeString($time);
        }
        if ($rule->trigger_type === 'holiday_date') {
            $date = $this->parseHolidayDate($config['date'] ?? null);

            return $date?->setTimeFromTimeString($time);
        }
        if ($rule->trigger_type !== 'date_offset') {
            return null;
        }
        $base = match ($config['field'] ?? null) {
            'created_at' => $customer->createdAt,
            'birth_date' => $customer->birthDate?->setYear($now->year),
            'wechat_added_on' => $customer->wechatAddedOn,
            'appointment_at' => $appointment?->scheduledAt,
            'completed_on' => $order?->completedOn,
            default => null,
        };
        if ($base === null) {
            return null;
        }
        $dueAt = $base->startOfDay()->addDays((int) ($config['offset_days'] ?? 0))->setTimeFromTimeString($time);
        if (($config['field'] ?? null) === 'birth_date' && $dueAt->isBefore($now->startOfDay())) {
            $dueAt = $dueAt->addYear();
        }

        return $dueAt;
    }

    private function parseHolidayDate(mixed $date): ?CarbonImmutable
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }

    /** @param array<string, array{key: string, parameters: array<string, scalar>}>|null $localizedContent */
    private function create(
        int $customerId,
        ?int $assignedTo,
        CarbonImmutable $dueAt,
        string $sourceType,
        string $reminderType,
        string $title,
        ?string $suggestion,
        int $priority,
        string $dedupeSource,
        ?int $ruleId = null,
        ?int $appointmentId = null,
        ?array $localizedContent = null,
    ): int {
        $reminder = Reminder::query()->firstOrCreate(
            ['dedupe_key' => hash('sha256', $dedupeSource)],
            [
                'rule_id' => $ruleId,
                'customer_id' => $customerId,
                'appointment_id' => $appointmentId,
                'assigned_to' => $assignedTo,
                'source_type' => $sourceType,
                'reminder_type' => $reminderType,
                'title' => $title,
                'suggestion' => $suggestion,
                'localized_content' => $localizedContent,
                'priority' => $priority,
                'due_at' => $dueAt,
                'status' => 'pending',
                'notification_status' => 'pending',
            ],
        );
        if (! $reminder->wasRecentlyCreated) {
            if (! $reminder->due_at->equalTo($dueAt) && in_array($reminder->status, ['pending', 'snoozed', 'transferred'], true)) {
                $before = $reminder->due_at->toIso8601String();
                $reminder->update(['due_at' => $dueAt, 'status' => 'pending', 'notification_status' => 'pending']);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'rescheduled',
                    'properties' => ['before' => $before, 'after' => $dueAt->toIso8601String()],
                    'occurred_at' => now(),
                ]);
            }

            return 0;
        }
        ReminderEvent::query()->create([
            'reminder_id' => $reminder->id,
            'event' => 'generated',
            'properties' => ['source' => $sourceType, 'due_at' => $dueAt->toIso8601String()],
            'occurred_at' => now(),
        ]);

        return 1;
    }
}
