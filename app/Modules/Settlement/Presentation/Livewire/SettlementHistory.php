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
        $historical = $this->historicalQuery()
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

        $allHistorical = $this->historicalQuery()->get();
        $allDisplays = $display->forSettlements($allHistorical);
        $agentOptions = collect($allDisplays)
            ->map(static fn (array $agent, int $settlementId): array => [
                'id' => (int) ($agent['id'] ?? 0),
                'code' => $agent['code'],
                'name' => $agent['name'],
                'settlement_id' => $settlementId,
            ])
            ->filter(static fn (array $agent): bool => $agent['id'] > 0)
            ->unique('id')
            ->sortBy(fn (array $agent): string => $agent['name'].' '.$agent['code'])
            ->values();

        return view('livewire.settlements.settlement-history', [
            'settlements' => $historical,
            'agentDisplays' => $display->forSettlements($historical->getCollection()),
            'agentOptions' => $agentOptions,
        ])->title(__('settlements.archive.title'));
    }

    /** @return Builder<Settlement> */
    private function historicalQuery(): Builder
    {
        return Settlement::query()
            ->whereNull('settlement_run_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('settlement_run_members')
                ->whereColumn('settlement_run_members.settlement_id', 'settlements.id'))
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.$this->search.'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereRaw("COALESCE(snapshot->'agent'->>'code', '') ILIKE ?", [$term])
                        ->orWhereRaw("COALESCE(snapshot->'agent'->>'name', '') ILIKE ?", [$term])
                        ->orWhereRaw('CAST(agent_id AS TEXT) ILIKE ?', [$term]);
                });
            });
    }
}
