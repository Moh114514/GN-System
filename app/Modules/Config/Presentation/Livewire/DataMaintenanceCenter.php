<?php

namespace App\Modules\Config\Presentation\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('数据导入与迁移')]
class DataMaintenanceCenter extends Component
{
    public function render(): View
    {
        return view('livewire.configuration.data-maintenance-center');
    }
}
