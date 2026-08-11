<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\SettlementDisplayReader;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SettlementHistory extends Component
{
    use WithPagination;

    public string $month = '';

    public string $agentId = '';

    public string $status = '';

    public string $search = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'month' => ['except' => ''],
        'agentId' => ['except' => ''],
        'status' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function updatedAgentId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('month', 'agentId', 'status', 'search');
        $this->resetPage();
    }

    public function render(SettlementDisplayReader $display): View
    {
        $historical = $this->historicalQuery($display)
            ->when($this->month !== '' && preg_match('/^\d{4}-\d{2}$/', $this->month) === 1, function (Builder $query): void {
                $start = CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth();
                $query->whereDate('period_start', '>=', $start->toDateString())
                    ->whereDate('period_start', '<', $start->addMonth()->toDateString());
            })
            ->when($this->agentId !== '' && ctype_digit($this->agentId), fn (Builder $query): Builder => $query->where('agent_id', (int) $this->agentId))
            ->when($this->status !== '' && in_array($this->status, ['draft', 'paid', 'reconciled'], true), fn (Builder $query): Builder => $query->where('status', $this->status))
            ->latest('period_end')
            ->latest('id')
            ->paginate(24);

        return view('livewire.settlements.settlement-history', [
            'settlements' => $historical,
            'agentDisplays' => $display->forSettlements($historical->getCollection()),
            'agentOptions' => collect($display->agentOptions()),
        ])->title(__('settlements.archive.title'));
    }

    /** @return Builder<Settlement> */
    private function historicalQuery(SettlementDisplayReader $display): Builder
    {
        return Settlement::query()
            ->whereNull('settlement_run_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('settlement_run_members')
                ->whereColumn('settlement_run_members.settlement_id', 'settlements.id'))
            ->when($this->search !== '', function (Builder $query) use ($display): void {
                $term = '%'.$this->search.'%';
                $matchingAgentIds = $display->matchingAgentIds($this->search);
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereRaw("COALESCE(snapshot->'agent'->>'code', '') ILIKE ?", [$term])
                        ->orWhereRaw("COALESCE(snapshot->'agent'->>'name', '') ILIKE ?", [$term])
                        ->orWhereRaw('CAST(agent_id AS TEXT) ILIKE ?', [$term]);
                })->when($matchingAgentIds !== [], fn (Builder $query): Builder => $query->orWhereIn('agent_id', $matchingAgentIds));
            });
    }
}
