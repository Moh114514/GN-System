<?php

namespace App\Modules\Config\Presentation\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class UserAndNotificationSettings extends Component
{
    public string $activeTab = 'users';

    public function mount(): void
    {
        $tab = (string) request()->query('tab', 'users');
        $this->activeTab = in_array($tab, ['users', 'notifications'], true) ? $tab : 'users';
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, ['users', 'notifications'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function render(): View
    {
        return view('livewire.configuration.user-and-notification-settings')
            ->title(__('config.center.cards.users.title'));
    }
}
