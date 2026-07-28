<?php

namespace App\Modules\Reminder\Presentation\Livewire;

use App\Models\User;
use App\Modules\Reminder\Application\Services\ReminderRuleManager;
use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('新建提醒')]
class ReminderCreate extends Component
{
    public string $customerId = '';

    public string $assignedTo = '';

    public string $templateId = '';

    public string $title = '';

    public string $dueAt = '';

    public string $suggestion = '';

    public string $notes = '';

    public string $recurrenceUnit = '';

    public string $recurrenceInterval = '1';

    public bool $saveAsTemplate = false;

    public string $templateName = '';

    public function mount(ReminderRuleManager $rules): void
    {
        $rules->ensureSystemTemplates();
        $requestedCustomer = request()->integer('customer');
        if ($requestedCustomer > 0) {
            $this->customerId = (string) $requestedCustomer;
        }
        $this->assignedTo = (string) Auth::id();
        $this->dueAt = now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
    }

    public function updatedTemplateId(): void
    {
        if ($this->templateId === '') {
            return;
        }
        $template = ReminderTemplate::query()->where('is_active', true)->findOrFail((int) $this->templateId);
        $this->title = (string) $template->title;
        $this->suggestion = (string) $template->suggestion;
    }

    public function save(ReminderWorkspace $workspace, ReminderRuleManager $rules): void
    {
        $this->validate([
            'customerId' => ['required', 'integer'],
            'assignedTo' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'dueAt' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'suggestion' => ['nullable', 'string', 'max:1000'],
            'recurrenceUnit' => ['nullable', 'in:day,week,month'],
            'recurrenceInterval' => ['required_if:recurrenceUnit,day,week,month', 'integer', 'min:1', 'max:365'],
            'templateName' => ['required_if:saveAsTemplate,true', 'nullable', 'string', 'max:255'],
        ]);
        try {
            $workspace->createCustom(
                customerId: (int) $this->customerId,
                assignedTo: (int) $this->assignedTo,
                title: $this->title,
                dueAt: CarbonImmutable::parse($this->dueAt),
                notes: $this->notes,
                suggestion: $this->suggestion,
                recurrence: $this->recurrenceUnit === '' ? null : ['unit' => $this->recurrenceUnit, 'interval' => (int) $this->recurrenceInterval],
                templateId: $this->templateId === '' ? null : (int) $this->templateId,
                actorId: (int) Auth::id(),
            );
            if ($this->saveAsTemplate) {
                $rules->saveTemplate(null, $this->templateName, $this->title, $this->suggestion, 'manual', [], (int) Auth::id());
            }
            $this->redirectRoute('reminders.index', navigate: true);
        } catch (DomainException $exception) {
            $this->addError('reminder', $exception->getMessage());
        }
    }

    public function render(ReminderWorkspace $workspace): View
    {
        return view('livewire.reminders.reminder-create', [
            'customers' => $workspace->customerCandidates(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'templates' => ReminderTemplate::query()->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', Auth::id()))
                ->orderByDesc('is_system')->orderBy('name')->get(),
        ]);
    }
}
