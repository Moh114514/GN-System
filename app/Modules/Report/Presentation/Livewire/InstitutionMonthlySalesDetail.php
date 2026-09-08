<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Modules\Report\Application\Services\InstitutionMonthlySalesDetailService;
use App\Modules\Report\Application\Services\InstitutionMonthlySalesService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InstitutionMonthlySalesDetail extends Component
{
    public int $institutionId;

    public string $month = '';

    public string $agentId = '';

    public string $search = '';

    public string $sort = 'occurred_on';

    public string $direction = 'desc';

    public int $page = 1;

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, array<string, string|int>> */
    protected array $queryString = [
        'month' => ['except' => ''],
        'agentId' => ['except' => ''],
        'search' => ['except' => ''],
        'sort' => ['except' => 'occurred_on'],
        'direction' => ['except' => 'desc'],
        'page' => ['except' => 1],
    ];

    public function mount(int $institution, InstitutionMonthlySalesService $sales, InstitutionMonthlySalesDetailService $details): void
    {
        $this->institutionId = $institution;
        $this->month = $sales->normalizeMonth($this->month !== '' ? $this->month : $sales->currentMonth());
        $this->refreshSnapshot($sales, $details);
    }

    public function updated(string $property, InstitutionMonthlySalesService $sales, InstitutionMonthlySalesDetailService $details): void
    {
        if ($property !== 'page') {
            $this->page = 1;
        }

        try {
            if ($property === 'month') {
                $this->month = $sales->normalizeMonth($this->month);
            }
            if ($property === 'agentId') {
                $this->agentId = trim($this->agentId);
            }
            $this->refreshSnapshot($sales, $details);
            $this->resetValidation();
        } catch (\DomainException $exception) {
            $this->addError($property, $exception->getMessage());
        }
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page = min(max(1, (int) ($this->snapshot['orders_last_page'] ?? 1)), $this->page + 1);
    }

    public function render(): View
    {
        return view('livewire.reports.institution-monthly-sales-detail', [
            'detail' => $this->snapshot,
            'agentOptions' => array_values(array_filter(
                $this->snapshot['agents'] ?? [],
                static fn (array $agent): bool => $agent['id'] !== null,
            )),
        ])->title(__('institution_sales.detail.title'));
    }

    private function refreshSnapshot(InstitutionMonthlySalesService $sales, InstitutionMonthlySalesDetailService $details): void
    {
        $this->snapshot = $details->detail(
            month: $this->month,
            institutionId: $this->institutionId,
            agentId: $this->agentId === '' ? null : (int) $this->agentId,
            search: $this->search,
            sort: $this->sort,
            direction: $this->direction,
            page: $this->page,
        );
    }
}
