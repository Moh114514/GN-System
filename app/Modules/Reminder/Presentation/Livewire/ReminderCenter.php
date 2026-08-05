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
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('主动提醒')]
class ReminderCenter extends Component
{
    public string $type = '';

    public string $actionNotes = '';

    public string $snoozeUntil = '';

    public string $snoozeReason = '';

    public string $assigneeId = '';

    public function complete(int $id, ReminderWorkspace $workspace): void
    {
        $this->run(fn () => $workspace->complete($id, Auth::user(), $this->actionNotes), '提醒已完成。');
    }

    public function snooze(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['snoozeUntil' => ['required', 'date'], 'snoozeReason' => ['required', 'string', 'max:1000']]);
        $this->run(fn () => $workspace->snooze($id, CarbonImmutable::parse($this->snoozeUntil), $this->snoozeReason, Auth::user()), '提醒已延期。');
    }

    public function transfer(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['assigneeId' => ['required', 'integer']]);
        $this->run(fn () => $workspace->transfer($id, (int) $this->assigneeId, Auth::user()), '提醒已转交。');
    }

    public function cancel(int $id, ReminderWorkspace $workspace): void
    {
        $this->validate(['actionNotes' => ['required', 'string', 'max:1000']]);
        $this->run(fn () => $workspace->cancel($id, $this->actionNotes, Auth::user()), '提醒已关闭。');
    }

    public function retryNotification(int $id, ReminderNotificationDispatcher $dispatcher): void
    {
        $this->run(fn () => $dispatcher->retry($id, Auth::user()), '钉钉通知已重新进入队列。');
    }

    public function render(ReminderWorkspace $workspace): View
    {
        return view('livewire.reminders.reminder-center', [
            'reminders' => $workspace->paginate(Auth::user(), false, $this->type),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'stats' => Auth::user()->is_super_admin ? $workspace->completionStats() : null,
            'customerNames' => $workspace->customerNames(),
        ]);
    }

    private function run(\Closure $operation, string $message): void
    {
        try {
            $operation();
            $this->reset('actionNotes', 'snoozeUntil', 'snoozeReason', 'assigneeId');
            Flux::toast(variant: 'success', text: $message);
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }
}
