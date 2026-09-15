<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\CustomerOverviewService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CustomerOverview extends Component
{
    /** @var array<string, mixed> */
    public array $overview = [];

    public function mount(CustomerOverviewService $service): void
    {
        $this->loadOverview($service);
    }

    public function refreshOverview(CustomerOverviewService $service): void
    {
        $this->loadOverview($service);
    }

    public function render(): View
    {
        return view('livewire.customers.customer-overview');
    }

    private function loadOverview(CustomerOverviewService $service): void
    {
        $this->overview = $service->overview();
    }
}
