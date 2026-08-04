<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\ExchangeRateQuoteService;
use App\Modules\Settlement\Application\Services\SettlementGenerator;
use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
#[Title('月结详情')]
class SettlementDetail extends Component
{
    public int $settlementId;

    public string $exchangeRate = '';

    public string $rejectionReason = '';

    public string $suggestionReason = '';

    public string $correctionTarget = '';

    public string $correctionReason = '';

    public function mount(int $settlement, ExchangeRateQuoteService $quotes): void
    {
        $record = Settlement::query()->findOrFail($settlement);
        $this->settlementId = $settlement;
        if ($record->exchange_rate_krw_per_cny === null) {
            $record = $quotes->refreshFor($record);
        }
        $this->exchangeRate = (string) ($record->exchange_rate_krw_per_cny ?? '');
        $this->correctionTarget = $record->status === 'approved' ? 'settled' : 'pending_review';
    }

    public function reject(SettlementWorkflow $workflow): void
    {
        $this->validate(['rejectionReason' => ['required', 'string', 'max:2000']]);
        $this->run(fn () => $workflow->reject($this->settlementId, $this->rejectionReason, (int) Auth::id(), request()->ip()), '月结已驳回。');
    }

    public function approve(SettlementWorkflow $workflow): void
    {
        $this->validate(['exchangeRate' => ['required', 'numeric', 'gt:0']]);
        $this->run(fn () => $workflow->approve($this->settlementId, $this->exchangeRate, (int) Auth::id(), request()->ip()), '月结已审核通过，Word/PDF 已生成。');
    }

    public function refreshExchangeRateQuote(ExchangeRateQuoteService $quotes): void
    {
        $this->run(
            function () use ($quotes): void {
                $record = Settlement::query()->findOrFail($this->settlementId);
                $quotes->refreshFor($record, true);
            },
            '最新汇率报价已更新，请核对后提交审核。',
        );
    }

    public function settle(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->settle($this->settlementId, (int) Auth::id(), request()->ip()), '月结已确认结清。');
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
                ? '月结已回退到待审核，请先重新生成月结明细并核对汇率。'
                : '月结状态已更正，并已记录审计原因。',
        );
    }

    public function regenerateDocuments(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->regenerateDocuments($this->settlementId), 'Word/PDF 已重新生成。');
    }

    public function regenerateSettlement(SettlementGenerator $generator): void
    {
        $record = Settlement::query()->findOrFail($this->settlementId);
        $hasItems = DB::table('settlement_items')->where('settlement_id', $record->id)->exists();
        if ($record->status !== 'pending_review' || $hasItems || $record->settlement_run_id === null) {
            $this->addError('workflow', '只有已撤回明细的待审核月结可以重新生成。');

            return;
        }
        $this->run(
            fn () => $generator->generate((string) $record->settlement_run_id, (int) $record->agent_id),
            '月结明细已重新生成。',
        );
    }

    public function reviewSuggestion(int $id, bool $accept, SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->reviewSuggestion($id, $accept, $this->suggestionReason, (int) Auth::id()), $accept ? '等级建议已批准并安排下月生效。' : '等级建议已驳回。');
    }

    public function render(): View
    {
        $settlement = Settlement::query()->findOrFail($this->settlementId);
        $items = DB::table('settlement_items')->where('settlement_id', $settlement->id)->orderBy('id')->get();

        return view('livewire.settlements.settlement-detail', [
            'settlement' => $settlement,
            'items' => $items,
            'documents' => SettlementDocument::query()->where('settlement_id', $settlement->id)->get(),
            'suggestion' => SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->first(),
        ]);
    }

    private function run(\Closure $operation, string $message): void
    {
        try {
            $operation();
            $this->refreshExchangeRate();
            session()->flash('status', $message);
        } catch (DomainException $exception) {
            $this->addError('workflow', $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('workflow', '操作未完成，数据已回滚，请检查文档生成环境后重试。');
        }
    }

    private function refreshExchangeRate(): void
    {
        $record = Settlement::query()->find($this->settlementId);
        $this->exchangeRate = $record === null
            ? ''
            : (string) ($record->exchange_rate_krw_per_cny ?? '');
    }
}
