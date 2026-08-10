<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Data\SettlementRunStartResult;
use App\Modules\Settlement\Application\Services\SettlementDisplayReader;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class SettlementCenter extends Component
{
    /** @var array<int, string> */
    public array $collapsedRunIds = [];

    public string $boundaryDay = '1';

    public string $triggerTime = '09:00';

    public bool $confirmConfigurationChange = false;

    public string $historicalPeriodEnd = '';

    public function toggleRun(string $runId): void
    {
        if (in_array($runId, $this->collapsedRunIds, true)) {
            $this->collapsedRunIds = array_values(array_diff($this->collapsedRunIds, [$runId]));

            return;
        }

        $this->collapsedRunIds[] = $runId;
    }

    public function mount(SettlementPeriodCalculator $periods): void
    {
        $configuration = $periods->activeConfiguration(CarbonImmutable::now());
        $this->boundaryDay = (string) $configuration->boundary_day;
        $this->triggerTime = substr((string) $configuration->trigger_time, 0, 5);
    }

    public function generate(SettlementRunManager $manager): void
    {
        $this->flashStartResult($manager->startWithResult('manual', (int) Auth::id()), __('settlements.labels.current'));
    }

    public function generateHistorical(SettlementRunManager $manager): void
    {
        $this->validate([
            'historicalPeriodEnd' => ['required', 'date_format:Y-m-d'],
        ]);
        try {
            $this->flashStartResult($manager->startHistoricalWithResult($this->historicalPeriodEnd, (int) Auth::id()), __('settlements.labels.historical'));
        } catch (DomainException $exception) {
            $this->addError('historicalPeriodEnd', $exception->getMessage());
        }
    }

    public function retry(string $runId, SettlementRunManager $manager): void
    {
        $manager->retryFailed($runId);
        Flux::toast(variant: 'success', text: __('settlements.toasts.retry_failed'));
    }

    public function retryNotification(string $runId, SettlementNotificationDispatcher $dispatcher): void
    {
        try {
            $dispatcher->retry($runId);
            Flux::toast(variant: 'success', text: __('settlements.toasts.retry_notification'));
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function saveConfiguration(SettlementPeriodCalculator $periods): void
    {
        $this->validate([
            'boundaryDay' => ['required', 'integer', 'between:1,28'],
            'triggerTime' => ['required', 'date_format:H:i'],
        ]);
        $hasUnfinished = SettlementRun::query()->whereIn('status', ['queued', 'running', 'partial_failed'])->exists();
        if ($hasUnfinished && ! $this->confirmConfigurationChange) {
            Flux::toast(variant: 'danger', text: __('settlements.toasts.configuration_confirmation_required'));

            return;
        }
        try {
            $configuration = $periods->saveConfiguration(
                (int) $this->boundaryDay,
                $this->triggerTime,
                (int) Auth::id(),
                CarbonImmutable::now(),
            );
            Flux::toast(variant: 'success', text: __('settlements.toasts.configuration_saved', ['date' => $configuration->effective_from->format('Y-m-d')]));
            $this->confirmConfigurationChange = false;
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function render(SettlementDisplayReader $display): View
    {
        $periods = app(SettlementPeriodCalculator::class)->recentClosedPeriods(CarbonImmutable::now(), 13);
        $runs = SettlementRun::query()
            ->with(['settlements', 'members.settlement'])
            ->latest('period_end')
            ->limit(24)
            ->get();
        $memberDisplays = [];
        $legacyDisplays = [];
        foreach ($runs as $run) {
            $memberDisplays[(string) $run->id] = $display->forMembers($run->members);
            foreach ($display->forSettlements($run->settlements) as $settlementId => $agentDisplay) {
                $legacyDisplays[$settlementId] = $agentDisplay;
            }
        }
        $unboundSettlements = Settlement::query()
            ->whereNull('settlement_run_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('settlement_run_members')
                ->whereColumn('settlement_run_members.settlement_id', 'settlements.id'))
            ->latest('period_end')
            ->latest('id')
            ->limit(24)
            ->get();
        $documentCounts = SettlementDocument::query()
            ->join('settlement_run_members', 'settlement_run_members.settlement_id', '=', 'settlement_documents.settlement_id')
            ->whereIn('settlement_run_members.settlement_run_id', $runs->pluck('id'))
            ->selectRaw('settlement_run_members.settlement_run_id as run_id, COUNT(DISTINCT settlement_documents.id) as document_count')
            ->groupBy('settlement_run_members.settlement_run_id')
            ->pluck('document_count', 'run_id')
            ->mapWithKeys(static fn ($count, $runId): array => [(string) $runId => (int) $count])
            ->all();

        return view('livewire.settlements.settlement-center', [
            'runs' => $runs,
            'memberDisplays' => $memberDisplays,
            'legacyDisplays' => $legacyDisplays,
            'documentCounts' => $documentCounts,
            'unboundSettlements' => $unboundSettlements,
            'unboundDisplays' => $display->forSettlements($unboundSettlements),
            'historicalPeriods' => array_slice($periods, 1),
        ]);
    }

    private function flashStartResult(SettlementRunStartResult $result, string $label): void
    {
        $messageKey = match ($result->outcome) {
            'created_and_dispatched', 'created_and_completed', 'created_partial_failed',
            'existing_running', 'existing_completed', 'existing_partial_failed' => $result->outcome,
            default => 'existing_other',
        };
        $key = str_starts_with($result->outcome, 'existing_') || $result->outcome === 'created_partial_failed'
            ? 'warning'
            : 'status';
        Flux::toast(
            variant: $key === 'warning' ? 'warning' : 'success',
            text: __('settlements.toasts.'.$messageKey, ['label' => $label, 'id' => $result->run->id]),
        );
    }
}
