<?php

namespace App\Modules\Reminder\Presentation\Livewire;

use App\Modules\Reminder\Application\Services\ReminderContentPresenter;
use App\Modules\Reminder\Application\Services\ReminderRuleManager;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReminderConfiguration extends Component
{
    public ?int $editingRuleId = null;

    public string $ruleName = '';

    public string $triggerType = 'date_offset';

    public string $dateField = 'created_at';

    public string $offsetDays = '0';

    public string $holidayDate = '';

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
            'triggerType' => ['required', 'in:status_change,date_offset,fixed_cycle,holiday_date,manual'],
            'ruleTitle' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'between:1,5'],
        ]);
        $config = ['time' => $this->triggerTime];
        if ($this->triggerType === 'date_offset') {
            $config += ['field' => $this->dateField, 'offset_days' => (int) $this->offsetDays];
        } elseif ($this->triggerType === 'fixed_cycle') {
            $config += ['interval_days' => (int) $this->intervalDays];
        } elseif ($this->triggerType === 'holiday_date') {
            $this->validate(['holidayDate' => ['required', 'date_format:Y-m-d']]);
            $config += ['date' => $this->holidayDate];
        }
        try {
            $manager->saveRule(
                $this->editingRuleId, $this->ruleName, $this->triggerType, $config, $this->scopeType,
                $this->scopeValue === '' ? [] : ['value' => $this->scopeValue],
                $this->ruleTitle, $this->suggestion, (int) $this->priority, (int) Auth::id(),
            );
            Flux::toast(variant: 'success', text: __('reminders.toasts.rule_saved'));
            $this->reset('editingRuleId', 'ruleName', 'ruleTitle', 'suggestion', 'scopeValue', 'holidayDate');
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
        $this->holidayDate = (string) ($rule->trigger_config['date'] ?? '');
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
        Flux::toast(variant: 'success', text: __('reminders.toasts.template_saved'));
    }

    public function editTemplate(int $id): void
    {
        $template = ReminderTemplate::query()->whereNull('owner_id')->findOrFail($id);
        $content = app(ReminderContentPresenter::class)->template($template);
        $this->editingTemplateId = $id;
        $this->templateName = $content['name'];
        $this->templateTitle = $content['title'];
        $this->templateSuggestion = (string) $content['suggestion'];
    }

    public function copyTemplate(int $id, ReminderRuleManager $manager): void
    {
        $template = ReminderTemplate::query()->whereNull('owner_id')->findOrFail($id);
        $content = app(ReminderContentPresenter::class)->template($template);
        $manager->saveTemplate(
            null,
            __('reminders.copy_suffix', ['name' => $content['name']]),
            $content['title'],
            $content['suggestion'],
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
        $content = app(ReminderContentPresenter::class);
        $templates = ReminderTemplate::query()->whereNull('owner_id')->orderByDesc('is_system')->get()
            ->map(function (ReminderTemplate $template) use ($content): ReminderTemplate {
                $values = $content->template($template);
                $template->setAttribute('name', $values['name']);
                $template->setAttribute('title', $values['title']);
                $template->setAttribute('suggestion', $values['suggestion']);

                return $template;
            });

        return view('livewire.reminders.reminder-configuration', [
            'rules' => ReminderRule::query()->latest()->get(),
            'templates' => $templates,
            'dingtalkEnabled' => (bool) config('dingtalk.enabled') && config('dingtalk.webhook_url'),
        ])->title(__('reminders.titles.configuration'));
    }
}
