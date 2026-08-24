<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Settlement\Application\Data\SettlementRunStartResult;
use App\Modules\Settlement\Application\Services\SettlementCalculationService;
use App\Modules\Settlement\Application\Services\SettlementDisplayReader;
use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementReadScope;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Application\Services\SettlementRunReconciler;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class SettlementCenter extends Component
{
    /** @var array<int, string> */
    public array $collapsedRunIds = [];

    public string $triggerTime = '09:00';

    public bool $confirmConfigurationChange = false;

    public string $selectedPeriodEnd = '';

    public string $historicalPeriodEnd = '';

    /** @var array<int, array<string, mixed>> */
    public array $previewResults = [];

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'selectedPeriodEnd' => ['except' => ''],
    ];

    public function toggleRun(string $runId): void
    {
        if (in_array($runId, $this->collapsedRunIds, true)) {
            $this->collapsedRunIds = array_values(array_diff($this->collapsedRunIds, [$runId]));

            return;
        }

        $this->collapsedRunIds[] = $runId;
    }

    public function mount(SettlementPeriodCalculator $periods, BusinessClock $clock): void
    {
        $configuration = $periods->activeConfiguration($clock->now());
        $this->triggerTime = substr((string) $configuration->trigger_time, 0, 5);
        if ($this->selectedPeriodEnd === '') {
            $latestRun = SettlementRun::query()->latest('period_end')->first();
            $this->selectedPeriodEnd = $latestRun?->period_end?->toDateString() ?? '';
        }
    }

    public function updatedSelectedPeriodEnd(): void
    {
        $this->collapsedRunIds = [];
        $this->previewResults = [];
    }

    public function preview(SettlementCalculationService $calculation, SettlementReadScope $scope, BusinessClock $clock): void
    {
        abort_unless(Auth::user()?->isSuperAdmin() || Auth::user()?->isBdManager(), 403);
        $periodEnd = $this->selectedPeriodEnd;
        if ($periodEnd === '') {
            $periodEnd = app(SettlementPeriodCalculator::class)->latestClosedPeriod($clock->now())->end->toDateString();
        }
        $period = app(SettlementPeriodCalculator::class)->recentClosedPeriods($clock->now(), 24);
        $selected = collect($period)->first(fn ($candidate): bool => $candidate->end->toDateString() === $periodEnd);
        abort_unless($selected !== null, 422);

        $agentIds = collect($calculation->eligibleAgentIds($selected->start, $selected->end))
            ->map(static fn (int $id): int => $id)
            ->when(! $scope->isAdmin(), fn ($ids) => $ids->filter(
                fn (int $id): bool => in_array($id, $scope->agentIds(), true),
            ))
            ->unique()
            ->values();
        $this->previewResults = [];
        foreach ($agentIds as $agentId) {
            try {
                $result = $calculation->calculate($agentId, $selected->start, $selected->end);
                $this->previewResults[] = [
                    'agent_id' => $agentId,
                    'agent' => $result['agent']->code.' '.$result['agent']->name,
                    'order_count' => $result['item_count'],
                    'consumption_krw' => $result['total_consumption_krw'],
                    'commission_krw' => $result['total_commission_krw'],
                    'error' => null,
                ];
            } catch (Throwable $exception) {
                report($exception);
                $this->previewResults[] = [
                    'agent_id' => $agentId,
                    'agent' => '#'.$agentId,
                    'order_count' => 0,
                    'consumption_krw' => 0,
                    'commission_krw' => 0,
                    'error' => __('settlements.center.preview_error'),
                ];
            }
        }
    }

    public function generate(SettlementRunManager $manager): void
    {
        $this->assertAdmin();
        $this->flashStartResult($manager->startWithResult('manual', (int) Auth::id()), __('settlements.labels.current'));
    }

    public function generateHistorical(SettlementRunManager $manager): void
    {
        $this->assertAdmin();
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
        $this->assertAdmin();
        $manager->retryFailed($runId);
        Flux::toast(variant: 'success', text: __('settlements.toasts.retry_failed'));
    }

    public function redispatchPending(string $runId, SettlementRunManager $manager, SettlementRunReconciler $reconciler): void
    {
        $this->assertAdmin();
        $run = SettlementRun::query()->findOrFail($runId);
        if (! $reconciler->isAnomalous($run)) {
            Flux::toast(variant: 'warning', text: __('settlements.queue_recovery.not_needed'));

            return;
        }

        $manager->redispatchPending($runId);
        Flux::toast(variant: 'success', text: __('settlements.queue_recovery.submitted'));
    }

    public function retryNotification(string $runId, SettlementNotificationDispatcher $dispatcher): void
    {
        $this->assertAdmin();
        try {
            $dispatcher->retry($runId);
            Flux::toast(variant: 'success', text: __('settlements.toasts.retry_notification'));
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function regenerateDocuments(int $settlementId, SettlementWorkflow $workflow): void
    {
        $this->assertAdmin();
        try {
            $workflow->regenerateDocuments($settlementId);
            Flux::toast(variant: 'success', text: __('settlements.toasts.documents_regenerated'));
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('settlements.toasts.operation_failed'));
        }
    }

    public function saveConfiguration(SettlementPeriodCalculator $periods, BusinessClock $clock): void
    {
        $this->assertAdmin();
        $this->validate([
            'triggerTime' => ['required', 'date_format:H:i'],
        ]);
        $hasUnfinished = SettlementRun::query()->whereIn('status', ['queued', 'running', 'stalled', 'partial_failed'])->exists();
        if ($hasUnfinished && ! $this->confirmConfigurationChange) {
            Flux::toast(variant: 'danger', text: __('settlements.toasts.configuration_confirmation_required'));

            return;
        }
        try {
            $configuration = $periods->saveConfiguration(
                $this->triggerTime,
                (int) Auth::id(),
                $clock->now(),
            );
            Flux::toast(variant: 'success', text: __('settlements.toasts.configuration_saved', ['date' => $configuration->effective_from->format('Y-m-d')]));
            $this->confirmConfigurationChange = false;
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function render(
        SettlementDisplayReader $display,
        SettlementRunReconciler $reconciler,
        BusinessClock $clock,
        SettlementReadScope $scope,
    ): View {
        $periods = app(SettlementPeriodCalculator::class)->recentClosedPeriods($clock->now(), 13);
        $runsQuery = SettlementRun::query();
        if (! $scope->isAdmin()) {
            $runsQuery->whereHas('settlements', fn ($query) => $query->whereIn('agent_id', $scope->agentIds()));
        }
        $availablePeriods = (clone $runsQuery)
            ->select(['period_start', 'period_end'])
            ->orderByDesc('period_end')
            ->get()
            ->unique(static fn (SettlementRun $run): string => $run->period_end->toDateString())
            ->values();
        $availablePeriodEnds = $availablePeriods->map(static fn (SettlementRun $run): string => $run->period_end->toDateString());
        if (! $availablePeriodEnds->contains($this->selectedPeriodEnd)) {
            $this->selectedPeriodEnd = $availablePeriodEnds->first() ?? '';
        }
        $runs = $runsQuery
            ->with(['settlements', 'members.settlement'])
            ->when($this->selectedPeriodEnd !== '', fn ($query) => $query->whereDate('period_end', $this->selectedPeriodEnd))
            ->latest('period_end')
            ->limit(24)
            ->get();
        $runSummaries = [];
        if (! $scope->isAdmin()) {
            $agentIds = $scope->agentIds();
            $runs->each(function (SettlementRun $run) use ($agentIds): void {
                $run->setRelation('settlements', $run->settlements->whereIn('agent_id', $agentIds)->values());
                $run->setRelation('members', $run->members->filter(
                    fn ($member): bool => in_array((int) $member->agent_id, $agentIds, true),
                )->values());
                $run->total_agents = $run->members->count() ?: $run->settlements->count();
                $run->processed_agents = $run->members->where('outcome', 'generated')->count();
                $run->existing_agents = $run->members->where('outcome', 'existing')->count();
                $run->failed_agents = $run->members->where('outcome', 'failed')->count();
                $run->total_consumption_krw = $run->settlements->sum('total_consumption_krw');
                $run->total_commission_krw = $run->settlements->sum('total_commission_krw');
                $run->notification_status = 'disabled';
            });
        }
        foreach ($runs as $run) {
            $runSummaries[(string) $run->id] = [
                'agents' => $run->settlements->count(),
                'consumption_krw' => (int) $run->settlements->sum('total_consumption_krw'),
                'commission_krw' => (int) $run->settlements->sum('total_commission_krw'),
            ];
        }
        $memberDisplays = [];
        $legacyDisplays = [];
        $queueStates = [];
        foreach ($runs as $run) {
            $memberDisplays[(string) $run->id] = $display->forMembers($run->members);
            $queueStates[(string) $run->id] = $scope->isAdmin()
                ? $reconciler->state($run)
                : ['anomalous' => false, 'pending_members' => 0, 'failed_jobs' => 0, 'pending_jobs' => 0];
            foreach ($display->forSettlements($run->settlements) as $settlementId => $agentDisplay) {
                $legacyDisplays[$settlementId] = $agentDisplay;
            }
        }
        $settlementIds = $runs->flatMap(static fn (SettlementRun $run): array => [
            ...$run->settlements->modelKeys(),
            ...$run->members->pluck('settlement_id')->filter()->all(),
        ])->filter()->unique()->values();
        $documentsBySettlement = $settlementIds->isEmpty()
            ? []
            : SettlementDocument::query()
                ->whereIn('settlement_id', $settlementIds)
                ->orderBy('format')
                ->get()
                ->groupBy(static fn (SettlementDocument $document): string => (string) $document->settlement_id)
                ->all();
        $historicalSettlementCount = Settlement::query()
            ->whereNull('settlement_run_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('settlement_run_members')
                ->whereColumn('settlement_run_members.settlement_id', 'settlements.id'))
            ->when(! $scope->isAdmin(), fn ($query) => $query->whereIn('agent_id', $scope->agentIds()))
            ->count();
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
            'documentsBySettlement' => $documentsBySettlement,
            'historicalSettlementCount' => $historicalSettlementCount,
            'historicalPeriods' => array_slice($periods, 1),
            'availablePeriods' => $availablePeriods,
            'selectedPeriodEnd' => $this->selectedPeriodEnd,
            'queueStates' => $queueStates,
            'runSummaries' => $runSummaries,
            'previewResults' => $this->previewResults,
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

    private function assertAdmin(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }
}
