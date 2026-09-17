<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Models\User;
use App\Modules\Report\Application\Services\InstitutionMonthlySalesService;
use App\Modules\Report\Application\Services\ReportExportManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class InstitutionMonthlySales extends Component
{
    public string $month = '';

    public string $institutionId = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'month' => ['except' => ''],
        'institutionId' => ['except' => ''],
    ];

    public function mount(InstitutionMonthlySalesService $sales): void
    {
        $this->month = $sales->normalizeMonth($this->month !== '' ? $this->month : $sales->currentMonth());
        try {
            $this->refreshSnapshot($sales);
        } catch (\DomainException $exception) {
            $this->institutionId = '';
            $this->addError('institutionId', $exception->getMessage());
            $this->refreshSnapshot($sales);
        }
    }

    public function updatedMonth(InstitutionMonthlySalesService $sales): void
    {
        if ($this->month === '') {
            $this->month = $sales->currentMonth();
        }
        try {
            $this->month = $sales->normalizeMonth($this->month);
            $this->resetValidation('month');
            $this->refreshSnapshot($sales);
        } catch (\DomainException $exception) {
            $this->addError('month', $exception->getMessage());
        }
    }

    public function updatedInstitutionId(InstitutionMonthlySalesService $sales): void
    {
        try {
            $this->resetValidation('institutionId');
            $this->refreshSnapshot($sales);
        } catch (\DomainException $exception) {
            $this->addError('institutionId', $exception->getMessage());
            $this->institutionId = '';
            $this->refreshSnapshot($sales);
        }
    }

    public function downloadExport(ReportExportManager $exports, string $format = 'xlsx'): void
    {
        $institutionId = $this->institutionId === '' ? null : (int) $this->institutionId;
        $export = $exports->startInstitutionMonthlySales($this->user(), $this->month, $institutionId, $format);
        if ($export->status !== 'completed') {
            Flux::toast(
                variant: 'danger',
                text: $exports->presentFailure($export) ?? __('institution_sales.errors.generic'),
            );

            return;
        }

        $this->redirectRoute('reports.exports.download', ['export' => $export]);
    }

    public function render(InstitutionMonthlySalesService $sales): View
    {
        return view('livewire.reports.institution-monthly-sales', [
            'summary' => $this->snapshot,
            'institutions' => $sales->institutionOptions(),
        ])->title(__('institution_sales.title'));
    }

    private function refreshSnapshot(InstitutionMonthlySalesService $sales): void
    {
        $institutionId = $this->institutionId === '' ? null : (int) $this->institutionId;
        $this->snapshot = $sales->summary($this->month, $institutionId)->toArray();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
