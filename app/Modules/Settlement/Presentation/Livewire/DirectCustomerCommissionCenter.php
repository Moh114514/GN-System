<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Settlement\Application\Services\DirectCustomerCommissionReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DirectCustomerCommissionCenter extends Component
{
    public string $month = '';

    public string $ownerId = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'month' => ['except' => ''],
        'ownerId' => ['except' => ''],
    ];

    public function mount(BusinessClock $clock): void
    {
        $this->month = $clock->now()->format('Y-m');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['month', 'ownerId'], true)) {
            $this->validateFilters();
        }
    }

    public function render(
        DirectCustomerCommissionReportService $report,
    ): View {
        $this->validateFilters();
        $ownerId = $this->ownerId === '' ? null : (int) $this->ownerId;
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->month.'-01', (string) config('app.timezone'));
        $snapshot = $report->snapshot($from, $from->endOfMonth(), $ownerId);

        return view('livewire.settlement.direct-customer-commission-center', [
            'records' => $snapshot['records'],
            'summaries' => $snapshot['summaries'],
            'totals' => $snapshot['totals'],
            'ownerOptions' => $snapshot['owner_options'],
        ])->title(__('settlements.direct_commission.title'));
    }

    private function validateFilters(): void
    {
        $this->month = trim($this->month);
        $this->ownerId = trim($this->ownerId);
        if (preg_match('/^(\d{4})-(\d{2})$/D', $this->month, $parts) !== 1
            || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
            abort(404);
        }
        if ($this->ownerId !== '' && preg_match('/^\d+$/D', $this->ownerId) !== 1) {
            abort(404);
        }
    }
}
