<?php

namespace App\Modules\Reminder\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use DomainException;

final readonly class ReminderRuleManager
{
    public const TRIGGER_TYPES = ['status_change', 'date_offset', 'fixed_cycle', 'manual'];

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
            throw new DomainException('提醒模板触发类型无效。');
        }
        $template = $id === null
            ? new ReminderTemplate(['is_active' => true, 'is_system' => false])
            : ReminderTemplate::query()->findOrFail($id);
        if ($template->is_system && $ownerId !== null) {
            throw new DomainException('系统模板不能改为个人模板。');
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
        foreach ([
            ['术前确认', '术前确认', '确认到店准备和注意事项', 'date_offset', ['field' => 'appointment_at', 'offset_days' => -3, 'time' => '09:00']],
            ['到院接待', '到院接待确认', '确认交通、住宿和接待流程', 'date_offset', ['field' => 'appointment_at', 'offset_days' => -1, 'time' => '18:00']],
            ['术后 1 天', '术后第 1 天跟进', '问候恢复情况', 'date_offset', ['field' => 'completed_on', 'offset_days' => 1, 'time' => '09:00']],
            ['术后 7 天', '术后第 7 天跟进', '确认恢复进度与注意事项', 'date_offset', ['field' => 'completed_on', 'offset_days' => 7, 'time' => '09:00']],
            ['术后 30 天', '术后第 30 天跟进', '确认阶段性恢复情况', 'date_offset', ['field' => 'completed_on', 'offset_days' => 30, 'time' => '09:00']],
            ['术后 90 天', '术后第 90 天跟进', '确认长期恢复与复诊需求', 'date_offset', ['field' => 'completed_on', 'offset_days' => 90, 'time' => '09:00']],
            ['术后 180 天', '术后第 180 天跟进', '完成长期关怀与复购需求确认', 'date_offset', ['field' => 'completed_on', 'offset_days' => 180, 'time' => '09:00']],
            ['生日问候', '生日问候', '生日关怀', 'date_offset', ['field' => 'birth_date', 'offset_days' => 0, 'time' => '09:00']],
            ['节日关怀', '节日客户关怀', '结合节日进行客户关怀', 'manual', []],
            ['老客户回访', '老客户定期回访', '了解近况与后续需求', 'fixed_cycle', ['interval_days' => 90, 'time' => '09:00']],
            ['沉默唤醒', '沉默客户唤醒', '主动了解客户近况', 'fixed_cycle', ['interval_days' => 180, 'time' => '09:00']],
            ['复购提醒', '复购需求确认', '根据既往项目确认后续需求', 'fixed_cycle', ['interval_days' => 180, 'time' => '09:00']],
        ] as [$name, $title, $suggestion, $type, $config]) {
            ReminderTemplate::query()->firstOrCreate(
                ['name' => $name, 'is_system' => true],
                [
                    'title' => $title,
                    'suggestion' => $suggestion,
                    'default_trigger_type' => $type,
                    'default_trigger_config' => $config,
                    'is_active' => true,
                ],
            );
        }
    }

    /** @param array<string, mixed> $config */
    private function validateRule(string $triggerType, array $config, string $scopeType, int $priority): void
    {
        if (! in_array($triggerType, self::TRIGGER_TYPES, true)) {
            throw new DomainException('提醒规则触发类型无效。');
        }
        if (! in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw new DomainException('提醒规则适用范围无效。');
        }
        if ($priority < 1 || $priority > 5) {
            throw new DomainException('提醒优先级必须在 1 至 5 之间。');
        }
        if ($triggerType === 'date_offset' && ! in_array($config['field'] ?? null, self::DATE_FIELDS, true)) {
            throw new DomainException('日期偏移规则字段无效。');
        }
        if ($triggerType === 'fixed_cycle' && (int) ($config['interval_days'] ?? 0) < 1) {
            throw new DomainException('周期提醒天数必须大于零。');
        }
        if (isset($config['time']) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string) $config['time']) !== 1) {
            throw new DomainException('提醒触发时间格式无效。');
        }
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
