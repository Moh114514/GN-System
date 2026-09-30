<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
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

    public string $dateField = 'created_at';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $sourceTypeFilter = '';

    public int $perPage = 20;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'institutionFilter' => ['except' => ''],
        'agentFilter' => ['except' => ''],
        'dateField' => ['except' => 'created_at'],
        'dateFrom' => ['except' => ''],
        'dateTo' => ['except' => ''],
        'sourceTypeFilter' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(OrderManagementWorkspace $workspace): void
    {
        $this->options = $workspace->options();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'institutionFilter', 'agentFilter', 'dateField', 'dateFrom', 'dateTo', 'sourceTypeFilter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'institutionFilter', 'agentFilter', 'dateFrom', 'dateTo', 'sourceTypeFilter');
        $this->dateField = 'created_at';
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(OrderManagementWorkspace $workspace): View
    {
        $this->validate([
            'dateField' => ['required', 'in:created_at,completed_at'],
            'dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
            'sourceTypeFilter' => ['nullable', 'in:agent,direct'],
        ]);

        if ($this->dateFrom !== '' && $this->dateTo !== '' && $this->dateTo < $this->dateFrom) {
            throw ValidationException::withMessages(['dateTo' => __('orders.errors.date_range')]);
        }

        $orders = $workspace->paginate([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'institution_id' => $this->institutionFilter === '' ? null : (int) $this->institutionFilter,
            'agent_id' => $this->agentFilter === '' ? null : (int) $this->agentFilter,
            'date_field' => $this->dateField,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'source_type' => $this->sourceTypeFilter,
        ], in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20);

        return view('livewire.orders.order-center', compact('orders'))->title(__('orders.title'));
    }
}
