<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\ExchangeRateQuoteService;
use App\Modules\Settlement\Application\Services\SettlementDisplayReader;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class SettlementDetail extends Component
{
    public int $settlementId;

    public string $exchangeRate = '';

    public string $rejectionReason = '';

    public string $suggestionReason = '';

    public string $correctionTarget = '';

    public string $correctionReason = '';

    public string $generationRecoveryBasis = '';

    public function mount(int $settlement, ExchangeRateQuoteService $quotes): void
    {
        $record = Settlement::query()->findOrFail($settlement);
        $this->settlementId = $settlement;
        if ($record->exchange_rate_krw_per_cny === null) {
            $record = $quotes->refreshFor($record);
        }
        $this->exchangeRate = (string) ($record->exchange_rate_krw_per_cny ?? '');
        $this->correctionTarget = $record->status === 'approved' ? 'settled' : 'pending_review';
        if ($this->hasBlockingGenerationState($record)) {
            $this->dispatch('business-alert-focus', alertId: 'settlement-generation-alert');
        }
    }

    public function reject(SettlementWorkflow $workflow): void
    {
        $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);
        $this->run(fn () => $workflow->reject($this->settlementId, $this->rejectionReason, (int) Auth::id(), request()->ip()), __('settlements.toasts.rejected'));
    }

    public function approve(SettlementWorkflow $workflow): void
    {
        $this->validate(['exchangeRate' => ['required', 'numeric', 'gt:0']]);
        $this->run(fn () => $workflow->approve($this->settlementId, $this->exchangeRate, (int) Auth::id(), request()->ip()), __('settlements.toasts.approved'));
    }

    public function refreshExchangeRateQuote(ExchangeRateQuoteService $quotes): void
    {
        try {
            $record = $quotes->refreshFor(Settlement::query()->findOrFail($this->settlementId), true);
            $this->refreshExchangeRate();
            if ($record->exchange_rate_quote_status === 'available') {
                Flux::toast(variant: 'success', text: __('settlements.toasts.quote_updated'));
            } elseif ($record->exchange_rate_quote_status === 'failed_retained_old_rate') {
                Flux::toast(variant: 'warning', text: __('settlements.toasts.quote_failed_retained'));
            } else {
                Flux::toast(variant: 'danger', text: __('settlements.toasts.quote_unavailable'));
            }
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('settlements.toasts.quote_error'));
        }
    }

    public function settle(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->settle($this->settlementId, (int) Auth::id(), request()->ip()), __('settlements.toasts.settled'));
    }

    public function correctStatus(SettlementWorkflow $workflow): void
    {
        $this->validate([
            'correctionTarget' => ['required', 'string', Rule::in(['pending_review', 'approved', 'settled'])],
            'correctionReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(
            fn () => $workflow->correctStatus($this->settlementId, $this->correctionTarget, $this->correctionReason, (int) Auth::id(), request()->ip()),
            $this->correctionTarget === 'pending_review'
                ? __('settlements.toasts.corrected_to_review')
                : __('settlements.toasts.corrected'),
        );
    }

    public function regenerateDocuments(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->regenerateDocuments($this->settlementId), __('settlements.toasts.documents_regenerated'));
    }

    public function regenerateSettlement(SettlementGenerator $generator): void
    {
        $record = Settlement::query()->findOrFail($this->settlementId);
        if (! in_array($record->status, ['pending_review', 'rejected'], true)
            || ! in_array($record->generation_status, ['pending', 'unverified'], true)
            || $record->settlement_run_id === null) {
            Flux::toast(variant: 'danger', text: __('settlements.toasts.regeneration_unavailable'));

            return;
        }
        $this->run(
            fn () => $generator->generate((string) $record->settlement_run_id, (int) $record->agent_id),
            __('settlements.toasts.settlement_regenerated'),
        );
    }

    public function recoverUnverifiedAsHistorical(SettlementWorkflow $workflow): void
    {
        $this->validateRecoveryBasis();
        $this->run(
            fn () => $workflow->recoverUnverifiedAsHistorical($this->settlementId, $this->generationRecoveryBasis, (int) Auth::id(), request()->ip()),
            __('settlements.toasts.historical_recovered'),
        );
    }

    public function createRecoveryBatch(SettlementWorkflow $workflow): void
    {
        $this->validateRecoveryBasis();
        $this->run(
            fn () => $workflow->recoverUnverifiedWithBatch($this->settlementId, $this->generationRecoveryBasis, (int) Auth::id(), request()->ip()),
            __('settlements.toasts.recovery_batch_created'),
        );
    }

    public function reviewSuggestion(int $id, bool $accept, SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->reviewSuggestion($id, $accept, $this->suggestionReason, (int) Auth::id()), $accept ? __('settlements.toasts.suggestion_approved') : __('settlements.toasts.suggestion_rejected'));
    }

    public function render(SettlementDisplayReader $display): View
    {
        $settlement = Settlement::query()->findOrFail($this->settlementId);
        $items = DB::table('settlement_items')->where('settlement_id', $settlement->id)->orderBy('id')->get();
        $previousSettlement = null;
        $nextSettlement = null;
        if ($settlement->settlement_run_id !== null) {
            $previousSettlement = Settlement::query()
                ->where('settlement_run_id', $settlement->settlement_run_id)
                ->where(function ($query) use ($settlement): void {
                    $query->where('agent_id', '<', $settlement->agent_id)
                        ->orWhere(function ($query) use ($settlement): void {
                            $query->where('agent_id', $settlement->agent_id)
                                ->where('id', '<', $settlement->id);
                        });
                })
                ->orderByDesc('agent_id')
                ->orderByDesc('id')
                ->first();
            $nextSettlement = Settlement::query()
                ->where('settlement_run_id', $settlement->settlement_run_id)
                ->where(function ($query) use ($settlement): void {
                    $query->where('agent_id', '>', $settlement->agent_id)
                        ->orWhere(function ($query) use ($settlement): void {
                            $query->where('agent_id', $settlement->agent_id)
                                ->where('id', '>', $settlement->id);
                        });
                })
                ->orderBy('agent_id')
                ->orderBy('id')
                ->first();
        }

        return view('livewire.settlements.settlement-detail', [
            'settlement' => $settlement,
            'agentDisplay' => $display->agent($settlement),
            'items' => $items,
            'documents' => SettlementDocument::query()->where('settlement_id', $settlement->id)->get(),
            'suggestion' => SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->first(),
            'previousSettlement' => $previousSettlement,
            'nextSettlement' => $nextSettlement,
        ])->title(__('settlements.titles.detail'));
    }

    private function run(\Closure $operation, string $message): void
    {
        try {
            $operation();
            $this->refreshExchangeRate();
            Flux::toast(variant: 'success', text: $message);
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            Flux::toast(variant: 'danger', text: __('settlements.toasts.operation_failed'));
        }
    }

    private function refreshExchangeRate(): void
    {
        $record = Settlement::query()->find($this->settlementId);
        $this->exchangeRate = $record === null
            ? ''
            : (string) ($record->exchange_rate_krw_per_cny ?? '');
    }

    private function validateRecoveryBasis(): void
    {
        $this->validate([
            'generationRecoveryBasis' => ['required', 'string', 'max:2000'],
        ]);
    }

    private function hasBlockingGenerationState(Settlement $settlement): bool
    {
        return $settlement->generation_status === 'unverified'
            || (in_array($settlement->status, ['pending_review', 'rejected'], true)
                && in_array($settlement->generation_status, ['pending', 'unverified'], true)
                && $settlement->settlement_run_id !== null);
    }
}
