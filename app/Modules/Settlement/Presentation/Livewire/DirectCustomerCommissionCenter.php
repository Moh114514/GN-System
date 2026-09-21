<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Contracts\DirectOrderCommissionGateway;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DirectCustomerCommissionCenter extends Component
{
    public function render(DirectOrderCommissionGateway $gateway): View
    {
        return view('livewire.settlement.direct-customer-commission-center', [
            'records' => $gateway->recordsForViewer(),
        ])->title(__('settlements.direct_commission.title'));
    }
}
