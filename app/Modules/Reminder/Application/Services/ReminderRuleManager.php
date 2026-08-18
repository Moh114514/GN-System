<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderEvent;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Lang;

final readonly class ReminderRuleManager
{
    public const TRIGGER_TYPES = ['status_change', 'date_offset', 'fixed_cycle', 'holiday_date', 'manual'];

    public const DATE_FIELDS = ['created_at', 'appointment_at', 'completed_on', 'birth_date', 'wechat_added_on'];

    public const SCOPE_TYPES = ['all_customers', 'agent', 'project', 'owner', 'cooperation_status'];

    public function __construct(private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $triggerConfig
     * @param  array<string, mixed>  $scopeConfig
     */
    public function saveRule(
        ?int $id,
        string $name,
        string $triggerType,
        array $triggerConfig,
        string $scopeType,
        array $scopeConfig,
        string $title,
        ?string $suggestion,
        int $priority,
        int $actorId,
    ): void {
        $this->validateRule($triggerType, $triggerConfig, $scopeType, $priority);
        $rule = $id === null ? new ReminderRule(['is_active' => true, 'is_system' => false]) : ReminderRule::query()->findOrFail($id);
        $before = $rule->exists ? $rule->getAttributes() : null;
        $rule->fill([
            'name' => trim($name),
            'trigger_type' => $triggerType,
            'trigger_config' => $triggerConfig,
            'scope_type' => $scopeType,
            'scope_config' => $scopeConfig,
            'title' => trim($title),
            'suggestion' => $this->nullable($suggestion),
            'priority' => $priority,
            'created_by' => $rule->created_by ?? $actorId,
        ])->save();
        $this->rescheduleHolidayReminders($rule, $before);
        $this->audit->record(
            description: '主动提醒规则已保存',
            properties: ['before' => $before, 'after' => $rule->getAttributes()],
            causerId: $actorId,
            subject: $rule,
            logName: 'reminder-configuration',
            event: $before === null ? 'created' : 'updated',
        );
    }

    public function toggleRule(int $id, int $actorId): void
    {
        $rule = ReminderRule::query()->findOrFail($id);
        $before = $rule->is_active;
        $rule->update(['is_active' => ! $before]);
        $this->audit->record(
            description: '主动提醒规则状态已变更',
            properties: ['before' => $before, 'after' => $rule->is_active],
            causerId: $actorId,
            subject: $rule,
            logName: 'reminder-configuration',
            event: 'status_changed',
        );
    }

    /** @param array<string, mixed> $triggerConfig */
    public function saveTemplate(
        ?int $id,
        string $name,
        string $title,
        ?string $suggestion,
        string $triggerType,
        array $triggerConfig,
        ?int $ownerId,
    ): void {
        if (! in_array($triggerType, self::TRIGGER_TYPES, true)) {
            throw new DomainException(__('reminders.errors.invalid_template_trigger'));
        }
        $template = $id === null
            ? new ReminderTemplate(['is_active' => true, 'is_system' => false])
            : ReminderTemplate::query()->findOrFail($id);
        if ($template->is_system && $ownerId !== null) {
            throw new DomainException(__('reminders.errors.system_template_owner'));
        }
        $template->fill([
            'name' => trim($name),
            'title' => trim($title),
            'suggestion' => $this->nullable($suggestion),
            'default_trigger_type' => $triggerType,
            'default_trigger_config' => $triggerConfig,
            'owner_id' => $ownerId,
        ])->save();
    }

    public function toggleTemplate(int $id): void
    {
        $template = ReminderTemplate::query()->findOrFail($id);
        $template->update(['is_active' => ! $template->is_active]);
    }

    public function ensureSystemTemplates(): void
    {
        foreach ($this->systemTemplateDefinitions() as $key => $definition) {
            $template = ReminderTemplate::query()->where('system_key', $key)->first();
            if ($template === null) {
                $template = $this->unkeyedSystemTemplate($key, $definition);
            }
            if ($template !== null) {
                if ($template->system_key === null) {
                    $template->update(['system_key' => $key]);
                }

                continue;
            }
            ReminderTemplate::query()->create([
                'system_key' => $key,
                'name' => $definition['name'],
                'title' => $definition['title'],
                'suggestion' => $definition['suggestion'],
                'default_trigger_type' => $definition['type'],
                'default_trigger_config' => $definition['config'],
                'is_system' => true,
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, mixed> $config */
    private function validateRule(string $triggerType, array $config, string $scopeType, int $priority): void
    {
        if (! in_array($triggerType, self::TRIGGER_TYPES, true)) {
            throw new DomainException(__('reminders.errors.invalid_rule_trigger'));
        }
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new DomainException(__('reminders.errors.invalid_rule_scope'));
        }
        if ($priority < 1 || $priority > 5) {
            throw new DomainException(__('reminders.errors.invalid_priority'));
        }
        if ($triggerType === 'date_offset' && ! in_array($config['field'] ?? null, self::DATE_FIELDS, true)) {
            throw new DomainException(__('reminders.errors.invalid_date_field'));
        }
        if ($triggerType === 'fixed_cycle' && (int) ($config['interval_days'] ?? 0) < 1) {
            throw new DomainException(__('reminders.errors.invalid_cycle_days'));
        }
        if ($triggerType === 'holiday_date' && ! $this->isValidHolidayDate($config['date'] ?? null)) {
            throw new DomainException(__('reminders.errors.invalid_holiday_date'));
        }
        if (isset($config['time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $config['time']) !== 1) {
            throw new DomainException(__('reminders.errors.invalid_trigger_time'));
        }
    }

    private function isValidHolidayDate(mixed $date): bool
    {
        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return false;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, (string) config('app.timezone'));
        } catch (\Throwable) {
            return false;
        }

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $date;
    }

    /** @param array<string, mixed>|null $before */
    private function rescheduleHolidayReminders(ReminderRule $rule, ?array $before): void
    {
        if ($before === null || ($before['trigger_type'] ?? null) !== 'holiday_date' || $rule->trigger_type !== 'holiday_date') {
            return;
        }

        $oldConfig = $this->configArray($before['trigger_config'] ?? null);
        $newConfig = $this->configArray($rule->trigger_config);
        if (($oldConfig['date'] ?? null) === ($newConfig['date'] ?? null)
            && ($oldConfig['time'] ?? null) === ($newConfig['time'] ?? null)) {
            return;
        }

        $date = $this->holidayDate($newConfig['date'] ?? null);
        if ($date === null) {
            return;
        }
        $dueAt = $date->setTimeFromTimeString((string) ($newConfig['time'] ?? '09:00'));

        Reminder::query()
            ->where('rule_id', $rule->id)
            ->whereIn('status', ['pending', 'snoozed', 'transferred'])
            ->get()
            ->each(function (Reminder $reminder) use ($rule, $dueAt, $newConfig): void {
                $dedupeKey = hash('sha256', "holiday-rule:{$rule->id}:{$reminder->customer_id}:{$newConfig['date']}");
                $existing = Reminder::query()
                    ->where('dedupe_key', $dedupeKey)
                    ->where('id', '!=', $reminder->id)
                    ->first();
                if ($existing !== null) {
                    $reminder->update(['status' => 'cancelled']);
                    ReminderEvent::query()->create([
                        'reminder_id' => $reminder->id,
                        'event' => 'cancelled',
                        'properties' => ['reason' => 'holiday_rule_rescheduled_duplicate', 'replacement_id' => $existing->id],
                        'occurred_at' => now(),
                    ]);

                    return;
                }

                $beforeDueAt = $reminder->due_at->toIso8601String();
                $reminder->update([
                    'dedupe_key' => $dedupeKey,
                    'due_at' => $dueAt,
                    'status' => 'pending',
                    'notification_status' => 'pending',
                ]);
                ReminderEvent::query()->create([
                    'reminder_id' => $reminder->id,
                    'event' => 'rescheduled',
                    'properties' => ['before' => $beforeDueAt, 'after' => $dueAt->toIso8601String()],
                    'occurred_at' => now(),
                ]);
            });
    }

    private function holidayDate(mixed $date): ?CarbonImmutable
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

    /** @return array<string, mixed> */
    private function configArray(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (! is_string($config) || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, array{name: string, title: string, suggestion: string, type: string, config: array<string, mixed>}> */
    private function systemTemplateDefinitions(): array
    {
        $schedules = [
            'pre_visit_confirmation' => ['date_offset', ['field' => 'appointment_at', 'offset_days' => -3, 'time' => '09:00']],
            'arrival_reception' => ['date_offset', ['field' => 'appointment_at', 'offset_days' => -1, 'time' => '18:00']],
            'post_treatment_7' => ['date_offset', ['field' => 'completed_on', 'offset_days' => 7, 'time' => '09:00']],
            'post_treatment_30' => ['date_offset', ['field' => 'completed_on', 'offset_days' => 30, 'time' => '09:00']],
            'birthday' => ['date_offset', ['field' => 'birth_date', 'offset_days' => 0, 'time' => '09:00']],
            'holiday' => ['manual', []],
        ];
        $definitions = [];
        foreach ($schedules as $key => [$type, $config]) {
            $translationKey = "reminders.system_templates.{$key}";
            $definitions[$key] = [
                'name' => (string) Lang::get("{$translationKey}.name", [], 'zh_CN'),
                'title' => (string) Lang::get("{$translationKey}.title", [], 'zh_CN'),
                'suggestion' => (string) Lang::get("{$translationKey}.suggestion", [], 'zh_CN'),
                'type' => $type,
                'config' => $config,
            ];
        }

        return $definitions;
    }

    /** @param array{name: string, title: string, suggestion: string, type: string, config: array<string, mixed>} $definition */
    private function unkeyedSystemTemplate(string $key, array $definition): ?ReminderTemplate
    {
        $templates = ReminderTemplate::query()
            ->where('is_system', true)
            ->whereNull('system_key')
            ->get();
        $translationKey = "reminders.system_templates.{$key}";
        $knownValues = [];
        foreach (['name', 'title', 'suggestion'] as $field) {
            $knownValues[$field] = [
                (string) Lang::get("{$translationKey}.{$field}", [], 'zh_CN'),
                (string) Lang::get("{$translationKey}.{$field}", [], 'ko_KR'),
            ];
        }
        $matched = $templates->first(fn (ReminderTemplate $template): bool => in_array((string) $template->name, $knownValues['name'], true)
            || in_array((string) $template->title, $knownValues['title'], true)
            || in_array((string) $template->suggestion, $knownValues['suggestion'], true));
        if ($matched instanceof ReminderTemplate) {
            return $matched;
        }

        $configured = $templates->first(fn (ReminderTemplate $template): bool => $template->default_trigger_type === $definition['type']
            && $template->default_trigger_config == $definition['config']);

        return $configured instanceof ReminderTemplate ? $configured : null;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
