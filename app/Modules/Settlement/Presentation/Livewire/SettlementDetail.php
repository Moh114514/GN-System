<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\SettlementWorkflow;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('月结详情')]
class SettlementDetail extends Component
{
    public int $settlementId;

    public string $exchangeRate = '';

    public string $rejectionReason = '';

    public string $suggestionReason = '';

    public function mount(int $settlement): void
    {
        Settlement::query()->findOrFail($settlement);
        $this->settlementId = $settlement;
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

    public function settle(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->settle($this->settlementId, (int) Auth::id(), request()->ip()), '月结已确认结清。');
    }

    public function regenerateDocuments(SettlementWorkflow $workflow): void
    {
        $this->run(fn () => $workflow->regenerateDocuments($this->settlementId), 'Word/PDF 已重新生成。');
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
            session()->flash('status', $message);
        } catch (DomainException $exception) {
            $this->addError('workflow', $exception->getMessage());
        }
    }
}
