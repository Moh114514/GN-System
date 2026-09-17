<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class OrderCenter extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $institutionFilter = '';

    public string $agentFilter = '';

    public int $perPage = 20;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'institutionFilter' => ['except' => ''],
        'agentFilter' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(OrderManagementWorkspace $workspace): void
    {
        $this->options = $workspace->options();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'institutionFilter', 'agentFilter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'institutionFilter', 'agentFilter');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(OrderManagementWorkspace $workspace): View
    {
        $orders = $workspace->paginate([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'institution_id' => $this->institutionFilter === '' ? null : (int) $this->institutionFilter,
            'agent_id' => $this->agentFilter === '' ? null : (int) $this->agentFilter,
        ], in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20);

        return view('livewire.orders.order-center', compact('orders'))->title(__('orders.title'));
    }
}
