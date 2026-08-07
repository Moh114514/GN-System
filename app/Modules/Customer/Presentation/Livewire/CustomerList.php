<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\CustomerDirectory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusId = '';

    public string $agentId = '';

    public string $institutionId = '';

    public int $perPage = 20;

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusId' => ['except' => ''],
        'agentId' => ['except' => ''],
        'institutionId' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(CustomerDirectory $directory): void
    {
        $this->options = $directory->options();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusId', 'agentId', 'institutionId', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusId', 'agentId', 'institutionId');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(CustomerDirectory $directory): View
    {
        $customers = $directory->paginate([
            'search' => $this->search,
            'status_id' => $this->statusId === '' ? null : (int) $this->statusId,
            'agent_id' => $this->agentId === '' ? null : (int) $this->agentId,
            'institution_id' => $this->institutionId === '' ? null : (int) $this->institutionId,
        ], in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20);

        return view('livewire.customers.customer-list', compact('customers'))
            ->title(__('customers.title.list'));
    }
}
