<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\DailyOrderWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerOrders extends Component
{
    public int $customerId;

    /** @var array<string, mixed> */
    public array $context = [];

    public function mount(int $customer, DailyOrderWorkspace $workspace): void
    {
        $this->customerId = $customer;
        $this->context = $workspace->context($this->customerId);
    }

    public function render(): View
    {
        return view('livewire.orders.customer-orders')->title(__('orders.customer_title'));
    }
}
