<?php

namespace App\Modules\Reminder\Presentation\Livewire;

use App\Models\User;
use App\Modules\Reminder\Application\Services\ReminderNotificationDispatcher;
use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReminderCenter extends Component
{
    /** @var list<string> */
    private const ACTION_MODES = ['complete', 'snooze', 'transfer', 'cancel'];

    public string $type = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'type' => ['except' => ''],
    ];

    public string $actionNotes = '';

    public string $snoozeUntil = '';

    public string $snoozeReason = '';

    public string $assigneeId = '';

    public ?int $activeReminderId = null;

    public string $actionMode = '';

    public function openAction(int $id, string $mode): void
    {
        if (! in_array($mode, self::ACTION_MODES, true)) {
            throw new \InvalidArgumentException('Unsupported reminder action mode.');
        }

        if ($this->activeReminderId === $id && $this->actionMode === $mode) {
            $this->resetAction();

            return;
        }

        $this->reset('actionNotes', 'snoozeUntil', 'snoozeReason', 'assigneeId');
        $this->activeReminderId = $id;
        $this->actionMode = $mode;
    }

    public function closeAction(): void
    {
        $this->resetAction();
    }

    public function complete(int $id, ReminderWorkspace $workspace): void
    {
        $this->run(fn () => $workspace->complete($id, Auth::user(), $this->actionNotes), __('reminders.toasts.completed'));
    }

    public function snooze(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['snoozeUntil' => ['required', 'date'], 'snoozeReason' => ['required', 'string', 'max:1000']]);
        $this->run(fn () => $workspace->snooze($id, CarbonImmutable::parse($this->snoozeUntil), $this->snoozeReason, Auth::user()), __('reminders.toasts.snoozed'));
    }

    public function transfer(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['assigneeId' => ['required', 'integer']]);
        $this->run(fn () => $workspace->transfer($id, (int) $this->assigneeId, Auth::user()), __('reminders.toasts.transferred'));
    }

    public function cancel(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['actionNotes' => ['required', 'string', 'max:1000']]);
        $this->run(fn () => $workspace->cancel($id, $this->actionNotes, Auth::user()), __('reminders.toasts.cancelled'));
    }

    public function retryNotification(int $id, ReminderNotificationDispatcher $dispatcher): void
    {
        $this->run(fn () => $dispatcher->retry($id, Auth::user()), __('reminders.toasts.retry_notification'));
    }

    public function render(ReminderWorkspace $workspace): View
    {
        return view('livewire.reminders.reminder-center', [
            'reminders' => $workspace->paginate(Auth::user(), false, $this->type),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'stats' => Auth::user()->is_super_admin ? $workspace->completionStats() : null,
            'customerNames' => $workspace->customerNames(),
        ])->title(__('reminders.titles.center'));
    }

    private function run(\Closure $operation, string $message): void
    {
        try {
            $operation();
            $this->resetAction();
            Flux::toast(variant: 'success', text: $message);
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    private function resetAction(): void
    {
        $this->reset('actionNotes', 'snoozeUntil', 'snoozeReason', 'assigneeId', 'activeReminderId', 'actionMode');
    }
}
