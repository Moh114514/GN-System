<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\SettlementDisplayReader;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SettlementHistory extends Component
{
    use WithPagination;

    public string $businessFrom = '';

    public string $businessTo = '';

    public string $agentId = '';

    public string $status = '';

    public string $search = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'businessFrom' => ['except' => ''],
        'businessTo' => ['except' => ''],
        'agentId' => ['except' => ''],
        'status' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    public function updated(string $property): void
    {
        if (in_array($property, ['businessFrom', 'businessTo', 'agentId', 'status', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('businessFrom', 'businessTo', 'agentId', 'status', 'search');
        $this->resetPage();
    }

    public function render(SettlementDisplayReader $display): View
    {
        $hasDateError = false;
        try {
            $this->validate($this->rules(), $this->messages());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $hasDateError = true;
        }

        $historical = $hasDateError
            ? new LengthAwarePaginator(new EloquentCollection, 0, 24, 1, ['path' => request()->url(), 'query' => request()->query()])
            : $this->historicalQuery($display)
                ->when($this->businessTo !== '', fn (Builder $query): Builder => $query->whereDate('period_start', '<=', $this->businessTo))
                ->when($this->businessFrom !== '', fn (Builder $query): Builder => $query->whereDate('period_end', '>=', $this->businessFrom))
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

    /** @return array<string, array<int, string>> */
    protected function rules(): array
    {
        return [
            'businessFrom' => ['nullable', 'date_format:Y-m-d'],
            'businessTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:businessFrom'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'businessFrom.date_format' => __('settlements.archive.validation.business_from_format'),
            'businessTo.date_format' => __('settlements.archive.validation.business_to_format'),
            'businessTo.after_or_equal' => __('settlements.archive.validation.business_range'),
        ];
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
                $query->where(function (Builder $query) use ($term, $matchingAgentIds): void {
                    $query->whereRaw("COALESCE(snapshot->'agent'->>'code', '') ILIKE ?", [$term])
                        ->orWhereRaw("COALESCE(snapshot->'agent'->>'name', '') ILIKE ?", [$term])
                        ->orWhereRaw('CAST(agent_id AS TEXT) ILIKE ?', [$term]);
                    if ($matchingAgentIds !== []) {
                        $query->orWhereIn('agent_id', $matchingAgentIds);
                    }
                });
            });
    }
}
