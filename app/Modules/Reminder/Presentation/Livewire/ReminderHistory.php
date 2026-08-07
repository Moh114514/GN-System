<?php

namespace App\Modules\Reminder\Presentation\Livewire;

use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReminderHistory extends Component
{
    public string $type = '';

    public function render(ReminderWorkspace $workspace): View
    {
        return view('livewire.reminders.reminder-history', [
            'reminders' => $workspace->paginate(Auth::user(), true, $this->type),
            'customerNames' => $workspace->customerNames(),
        ])->title(__('reminders.titles.history'));
    }
}
