<?php

namespace App\Modules\Agent\Presentation\Livewire;

use App\Modules\Agent\Application\Services\AgentDirectory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('代理商管理')]
class AgentList extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'search' => ['except' => ''],
    ];

    public string $status = '';

    public string $typeCodeId = '';

    public string $policySystemId = '';

    public string $policyGradeId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedTypeCodeId(): void
    {
        $this->resetPage();
    }

    public function updatedPolicySystemId(): void
    {
        $this->policyGradeId = '';
        $this->resetPage();
    }

    public function updatedPolicyGradeId(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'typeCodeId', 'policySystemId', 'policyGradeId');
        $this->resetPage();
    }

    public function render(AgentDirectory $directory): View
    {
        return view('livewire.agents.agent-list', [
            'agents' => $directory->paginate(
                search: $this->search,
                status: $this->status,
                typeCodeId: $this->typeCodeId === '' ? null : (int) $this->typeCodeId,
                policySystemId: $this->policySystemId === '' ? null : (int) $this->policySystemId,
                policyGradeId: $this->policyGradeId === '' ? null : (int) $this->policyGradeId,
            ),
            'filterOptions' => $directory->filterOptions(),
        ]);
    }
}
