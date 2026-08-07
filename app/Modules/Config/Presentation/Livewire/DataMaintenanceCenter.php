<?php

namespace App\Modules\Config\Presentation\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DataMaintenanceCenter extends Component
{
    public function render(): View
    {
        return view('livewire.configuration.data-maintenance-center')
            ->title(__('config.data_maintenance.title'));
    }
}
