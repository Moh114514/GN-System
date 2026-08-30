<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Models\User;
use App\Modules\Settlement\Application\Services\BdQuarterlyCommissionService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class BdQuarterlyCommissionCenter extends Component
{
    public string $quarterStart = '';

    public ?int $selectedPeriodId = null;

    public string $selectedBdUserId = '';

    public string $ruleEffectiveFrom = '';

    public string $ruleRateBps = '';

    public string $ruleReason = '';

    public string $adjustmentBdUserId = '';

    public string $adjustmentAmountKrw = '';

    public string $adjustmentReason = '';

    /** @var array<string, mixed> */
    public array $previewData = [];

    public ?string $error = null;

    public function mount(): void
    {
        $this->quarterStart = CarbonImmutable::now('Asia/Shanghai')->startOfQuarter()->toDateString();
        $this->ruleEffectiveFrom = CarbonImmutable::now('Asia/Shanghai')->startOfQuarter()->toDateString();
    }

    public function preview(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $this->previewData = $service->preview($this->date($this->quarterStart));
            $this->selectedPeriodId = $this->selectedPeriodId ?? $this->periodId($service);
        });
    }

    public function generate(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $period = $service->generate($this->date($this->quarterStart), $this->user()->id, request()->ip());
            $this->selectedPeriodId = (int) $period->id;
            $this->selectedBdUserId = '';
            $this->previewData = $service->preview($this->date($this->quarterStart));
        });
    }

    public function submitReview(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $service->review($this->selectedPeriodIdOrFail($service), $this->user()->id, request()->ip());
        });
    }

    public function confirm(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $service->confirm($this->selectedPeriodIdOrFail($service), $this->user()->id, request()->ip());
        });
    }

    public function saveRule(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $service->saveRule(
                'order_amount_krw',
                'KRW',
                (int) $this->ruleRateBps,
                $this->date($this->ruleEffectiveFrom),
                $this->ruleReason,
                $this->user()->id,
                request()->ip(),
            );
            $this->ruleReason = '';
        });
    }

    public function addAdjustment(BdQuarterlyCommissionService $service): void
    {
        $this->run(function () use ($service): void {
            $service->addAdjustment(
                $this->selectedPeriodIdOrFail($service),
                $this->adjustmentBdUserId === '' ? null : (int) $this->adjustmentBdUserId,
                (int) $this->adjustmentAmountKrw,
                $this->adjustmentReason,
                $this->user()->id,
                request()->ip(),
            );
            $this->adjustmentAmountKrw = '';
            $this->adjustmentReason = '';
        });
    }

    public function render(BdQuarterlyCommissionService $service): View
    {
        $periods = $service->visiblePeriods();
        $fullDetail = $this->selectedPeriodId === null ? null : $service->visibleDetail($this->selectedPeriodId);
        $detail = $fullDetail;
        $selectedBdUserId = $this->selectedBdUserId === '' ? null : (int) $this->selectedBdUserId;
        if ($fullDetail !== null && $selectedBdUserId !== null && auth()->user()?->is_super_admin) {
            $detail = $service->visibleDetail($this->selectedPeriodId, $selectedBdUserId);
        }
        $availableBdUsers = $fullDetail === null ? [] : collect($fullDetail['items'])
            ->concat($fullDetail['adjustments'])
            ->filter(fn (array $item): bool => isset($item['bd_user_id']))
            ->map(fn (array $item): array => ['id' => (int) $item['bd_user_id'], 'name' => (string) $item['bd_name']])
            ->unique('id')->values()->all();

        return view('livewire.settlements.bd-quarterly-commission-center', [
            'periods' => $periods,
            'detail' => $detail,
            'rules' => auth()->user()?->isSuperAdmin() ? $service->rules() : collect(),
            'users' => auth()->user()?->isSuperAdmin() ? $service->eligibleBdUsers() : [],
            'availableBdUsers' => $availableBdUsers,
        ])->title(__('settlements.bd_commission.title'));
    }

    private function run(callable $callback): void
    {
        $this->error = null;
        try {
            $callback();
        } catch (DomainException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    private function date(string $value): CarbonImmutable
    {
        $value = trim($value);
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            $date = false;
        }
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException(__('settlements.bd_commission.errors.date_invalid'));
        }

        return $date;
    }

    private function periodId(BdQuarterlyCommissionService $service): ?int
    {
        $period = $service->visiblePeriods()->first(fn ($period): bool => $period->quarter_start->format('Y-m-d') === $this->quarterStart);

        return $period?->id === null ? null : (int) $period->id;
    }

    private function selectedPeriodIdOrFail(BdQuarterlyCommissionService $service): int
    {
        if ($this->selectedPeriodId !== null) {
            return $this->selectedPeriodId;
        }
        $id = $this->periodId($service);
        if ($id === null) {
            throw new DomainException(__('settlements.bd_commission.errors.period_required'));
        }

        return $id;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
