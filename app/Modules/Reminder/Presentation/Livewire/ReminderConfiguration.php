<?php

namespace App\Modules\Reminder\Presentation\Livewire;

use App\Modules\Reminder\Application\Services\ReminderRuleManager;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('主动提醒配置')]
class ReminderConfiguration extends Component
{
    public ?int $editingRuleId = null;

    public string $ruleName = '';

    public string $triggerType = 'date_offset';

    public string $dateField = 'created_at';

    public string $offsetDays = '0';

    public string $intervalDays = '90';

    public string $triggerTime = '09:00';

    public string $scopeType = 'all_customers';

    public string $scopeValue = '';

    public string $ruleTitle = '';

    public string $suggestion = '';

    public string $priority = '3';

    public string $templateName = '';

    public string $templateTitle = '';

    public string $templateSuggestion = '';

    public ?int $editingTemplateId = null;

    public function mount(ReminderRuleManager $manager): void
    {
        $manager->ensureSystemTemplates();
    }

    public function saveRule(ReminderRuleManager $manager): void
    {
        $this->validate([
            'ruleName' => ['required', 'string', 'max:255'],
            'triggerType' => ['required', 'in:status_change,date_offset,fixed_cycle,manual'],
            'ruleTitle' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'between:1,5'],
        ]);
        $config = ['time' => $this->triggerTime];
        if ($this->triggerType === 'date_offset') {
            $config += ['field' => $this->dateField, 'offset_days' => (int) $this->offsetDays];
        } elseif ($this->triggerType === 'fixed_cycle') {
            $config += ['interval_days' => (int) $this->intervalDays];
        }
        try {
            $manager->saveRule(
                $this->editingRuleId, $this->ruleName, $this->triggerType, $config, $this->scopeType,
                $this->scopeValue === '' ? [] : ['value' => $this->scopeValue],
                $this->ruleTitle, $this->suggestion, (int) $this->priority, (int) Auth::id(),
            );
            Flux::toast(variant: 'success', text: '主动提醒规则已保存。');
            $this->reset('editingRuleId', 'ruleName', 'ruleTitle', 'suggestion', 'scopeValue');
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function toggleRule(int $id, ReminderRuleManager $manager): void
    {
        $manager->toggleRule($id, (int) Auth::id());
    }

    public function editRule(int $id): void
    {
        $rule = ReminderRule::query()->findOrFail($id);
        $this->editingRuleId = $id;
        $this->ruleName = (string) $rule->name;
        $this->triggerType = (string) $rule->trigger_type;
        $this->dateField = (string) ($rule->trigger_config['field'] ?? 'created_at');
        $this->offsetDays = (string) ($rule->trigger_config['offset_days'] ?? 0);
        $this->intervalDays = (string) ($rule->trigger_config['interval_days'] ?? 90);
        $this->triggerTime = (string) ($rule->trigger_config['time'] ?? '09:00');
        $this->scopeType = (string) $rule->scope_type;
        $this->scopeValue = (string) ($rule->scope_config['value'] ?? '');
        $this->ruleTitle = (string) $rule->title;
        $this->suggestion = (string) $rule->suggestion;
        $this->priority = (string) $rule->priority;
    }

    public function saveTemplate(ReminderRuleManager $manager): void
    {
        $this->validate(['templateName' => ['required', 'string', 'max:255'], 'templateTitle' => ['required', 'string', 'max:255']]);
        $manager->saveTemplate($this->editingTemplateId, $this->templateName, $this->templateTitle, $this->templateSuggestion, 'manual', [], null);
        $this->reset('editingTemplateId', 'templateName', 'templateTitle', 'templateSuggestion');
        Flux::toast(variant: 'success', text: '全局提醒模板已保存。');
    }

    public function editTemplate(int $id): void
    {
        $template = ReminderTemplate::query()->whereNull('owner_id')->findOrFail($id);
        $this->editingTemplateId = $id;
        $this->templateName = (string) $template->name;
        $this->templateTitle = (string) $template->title;
        $this->templateSuggestion = (string) $template->suggestion;
    }

    public function copyTemplate(int $id, ReminderRuleManager $manager): void
    {
        $template = ReminderTemplate::query()->whereNull('owner_id')->findOrFail($id);
        $manager->saveTemplate(
            null,
            $template->name.' 副本',
            (string) $template->title,
            $template->suggestion,
            (string) $template->default_trigger_type,
            $template->default_trigger_config,
            null,
        );
    }

    public function toggleTemplate(int $id, ReminderRuleManager $manager): void
    {
        $manager->toggleTemplate($id);
    }

    public function render(): View
    {
        return view('livewire.reminders.reminder-configuration', [
            'rules' => ReminderRule::query()->latest()->get(),
            'templates' => ReminderTemplate::query()->whereNull('owner_id')->orderByDesc('is_system')->get(),
            'dingtalkEnabled' => (bool) config('dingtalk.enabled') && config('dingtalk.webhook_url'),
        ]);
    }
}
