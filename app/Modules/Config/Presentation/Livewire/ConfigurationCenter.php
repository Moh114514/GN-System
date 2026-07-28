<?php

namespace App\Modules\Config\Presentation\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('配置中心')]
class ConfigurationCenter extends Component
{
    public function render(): View
    {
        return view('livewire.configuration.configuration-center');
    }
}
