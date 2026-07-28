<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SettlementWorkflow
{
    public function __construct(
        private SettlementDocumentGenerator $documents,
        private SettlementAgentGateway $agents,
        private AuditRecorder $audit,
    ) {}

    public function reject(int $settlementId, string $reason, int $actorId, ?string $ipAddress): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('驳回月结必须填写原因。');
        }
        $settlement = Settlement::query()->findOrFail($settlementId);
        if ($settlement->status !== 'pending_review') {
            throw new DomainException('只有待审核月结可以驳回。');
        }
        $settlement->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
        ]);
        $this->record($settlement, '月结已驳回', 'rejected', $actorId, $ipAddress);
    }

    public function approve(int $settlementId, string $exchangeRate, int $actorId, ?string $ipAddress): void
    {
        $rate = BigDecimal::of(trim($exchangeRate));
        if ($rate->isLessThanOrEqualTo(0)) {
            throw new DomainException('结算汇率必须大于零。');
        }
        DB::transaction(function () use ($settlementId, $rate, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if (! in_array($settlement->status, ['pending_review', 'rejected'], true)) {
                throw new DomainException('当前月结状态不可审核通过。');
            }
            $payoutFen = BigDecimal::of($settlement->total_commission_krw)
                ->dividedBy($rate, 8, RoundingMode::HalfUp)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();
            $settlement->update([
                'status' => 'approved',
                'exchange_rate_krw_per_cny' => (string) $rate,
                'payout_amount_cny_fen' => $payoutFen,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
            $this->record($settlement, '月结已审核通过', 'approved', $actorId, $ipAddress);
        });
        $this->documents->generate(Settlement::query()->findOrFail($settlementId));
    }

    public function settle(int $settlementId, int $actorId, ?string $ipAddress): void
    {
        $settlement = Settlement::query()->findOrFail($settlementId);
        if ($settlement->status !== 'approved') {
            throw new DomainException('只有已审核月结可以确认结清。');
        }
        $settlement->update([
            'status' => 'settled',
            'settled_on' => now()->toDateString(),
            'settled_by' => $actorId,
            'confirmed_at' => now(),
        ]);
        $this->record($settlement, '月结已确认结清', 'settled', $actorId, $ipAddress);
    }

    public function regenerateDocuments(int $settlementId): void
    {
        $settlement = Settlement::query()->findOrFail($settlementId);
        if (! in_array($settlement->status, ['approved', 'settled'], true)) {
            throw new DomainException('只有已审核月结可以生成结算单。');
        }
        $this->documents->generate($settlement);
    }

    public function reviewSuggestion(int $suggestionId, bool $accept, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($suggestionId, $accept, $reason, $actorId): void {
            $suggestion = SettlementGradeSuggestion::query()->lockForUpdate()->findOrFail($suggestionId);
            if ($suggestion->status !== 'pending') {
                throw new DomainException('该等级建议已经处理。');
            }
            if ($accept) {
                $settlement = Settlement::query()->findOrFail($suggestion->settlement_id);
                $this->agents->scheduleGrade(
                    (int) $suggestion->agent_id,
                    (int) $suggestion->recommended_grade_id,
                    CarbonImmutable::parse($settlement->period_end)->addMonthNoOverflow()->startOfMonth(),
                    $actorId,
                    trim($reason) === '' ? '月结等级建议人工批准' : trim($reason),
                );
            }
            $suggestion->update([
                'status' => $accept ? 'accepted' : 'rejected',
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_reason' => trim($reason) ?: null,
            ]);
        });
    }

    private function record(Settlement $settlement, string $description, string $event, int $actorId, ?string $ipAddress): void
    {
        $this->audit->record(
            description: $description,
            properties: ['status' => $settlement->status, 'settlement_id' => $settlement->id],
            causerId: $actorId,
            subject: $settlement,
            logName: 'settlement',
            event: $event,
            ipAddress: $ipAddress,
        );
    }
}
