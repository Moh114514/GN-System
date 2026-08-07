<?php

namespace App\Modules\Config\Presentation\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ConfigurationCenter extends Component
{
    public function render(): View
    {
        return view('livewire.configuration.configuration-center')
            ->title(__('config.center.title'));
    }
}
