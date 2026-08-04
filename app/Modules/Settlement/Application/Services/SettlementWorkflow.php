<?php

namespace App\Modules\Settlement\Application\Services;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SettlementWorkflow
{
    public function __construct(
        private SettlementDocumentGenerator $documents,
        private SettlementAgentGateway $agents,
        private AuditRecorder $audit,
        private SettlementGenerator $generator,
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
        $rate = $this->normaliseRate($exchangeRate);
        DB::transaction(function () use ($settlementId, $rate, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if (! in_array($settlement->status, ['pending_review', 'rejected'], true)) {
                throw new DomainException('当前月结状态不可审核通过。');
            }
            if ($settlement->generation_status !== 'generated') {
                throw new DomainException('月结明细尚未生成，请先重新生成月结明细后再审核。');
            }
            $manualOverride = $settlement->exchange_rate_quote_status !== 'available'
                || $settlement->exchange_rate_krw_per_cny === null
                || (string) $settlement->exchange_rate_krw_per_cny !== (string) $rate;
            $payoutFen = BigDecimal::of($settlement->total_commission_krw)
                ->dividedBy($rate, 8, RoundingMode::HalfUp)
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();
            $settlement->update([
                'status' => 'approved',
                'exchange_rate_krw_per_cny' => (string) $rate,
                'exchange_rate_manual_override' => $manualOverride,
                'payout_amount_cny_fen' => $payoutFen,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);
            $this->documents->generate($settlement);
            $this->record($settlement, '月结已审核通过', 'approved', $actorId, $ipAddress, [
                'exchange_rate_krw_per_cny' => (string) $rate,
                'exchange_rate_quote_source' => $settlement->exchange_rate_quote_source,
                'exchange_rate_quoted_at' => $settlement->exchange_rate_quoted_at?->toIso8601String(),
                'exchange_rate_manual_override' => $manualOverride,
            ]);
        });
    }

    public function settle(int $settlementId, int $actorId, ?string $ipAddress): void
    {
        DB::transaction(function () use ($settlementId, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
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
        });
    }

    public function correctStatus(int $settlementId, string $targetStatus, string $reason, int $actorId, ?string $ipAddress): void
    {
        $targetStatus = trim($targetStatus);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('状态更正必须填写原因。');
        }
        if (! in_array($targetStatus, ['pending_review', 'approved', 'settled'], true)) {
            throw new DomainException('月结状态只能更正为待审核、已审核或已结清。');
        }

        DB::transaction(function () use ($settlementId, $targetStatus, $reason, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if (! in_array($settlement->status, ['approved', 'settled'], true)) {
                throw new DomainException('历史已结清或已对账月结不可更正。');
            }
            if ($settlement->status === $targetStatus) {
                throw new DomainException('目标状态与当前月结状态相同。');
            }

            $before = $this->statusSnapshot($settlement);
            $itemsRemoved = 0;
            $documentsRemoved = 0;
            if ($targetStatus === 'pending_review') {
                if (SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->where('status', 'accepted')->exists()) {
                    throw new DomainException('该月结等级建议已经生效，需先人工处理等级安排后才能回退。');
                }
                $itemsRemoved = DB::table('settlement_items')->where('settlement_id', $settlement->id)->delete();
                $documentsRemoved = $this->documents->discard((int) $settlement->id);
                SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->delete();
            }
            $attributes = match ($targetStatus) {
                'settled' => [
                    'status' => 'settled',
                    'settled_on' => now()->toDateString(),
                    'settled_by' => $actorId,
                    'confirmed_at' => now(),
                ],
                'approved' => [
                    'status' => 'approved',
                    'settled_on' => null,
                    'settled_by' => null,
                    'confirmed_at' => null,
                ],
                default => [
                    'status' => 'pending_review',
                    'settled_on' => null,
                    'settled_by' => null,
                    'confirmed_at' => null,
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'rejection_reason' => null,
                    'total_consumption_krw' => 0,
                    'total_commission_krw' => 0,
                    'payout_amount_cny_fen' => 0,
                    'generation_status' => 'pending',
                    'generated_at' => null,
                    'item_count' => 0,
                ],
            };
            $settlement->update($attributes);
            $settlement->refresh();
            $this->audit->record(
                description: '月结状态已人工更正',
                properties: [
                    'settlement_id' => $settlement->id,
                    'before' => $before,
                    'after' => $this->statusSnapshot($settlement),
                    'reason' => $reason,
                    'items_removed' => $itemsRemoved,
                    'documents_removed' => $documentsRemoved,
                ],
                causerId: $actorId,
                subject: $settlement,
                logName: 'settlement',
                event: 'status_corrected',
                ipAddress: $ipAddress,
            );
        });
    }

    public function regenerateDocuments(int $settlementId): void
    {
        $settlement = Settlement::query()->findOrFail($settlementId);
        if (! in_array($settlement->status, ['approved', 'settled'], true)) {
            throw new DomainException('只有已审核月结可以生成结算单。');
        }
        $this->documents->generate($settlement);
    }

    public function recoverUnverifiedAsHistorical(int $settlementId, string $basis, int $actorId, ?string $ipAddress): void
    {
        $this->assertSuperAdmin($actorId);
        $basis = $this->normaliseRecoveryBasis($basis);

        DB::transaction(function () use ($settlementId, $basis, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if ($settlement->generation_status !== 'unverified') {
                throw new DomainException('只有无法确认生成来源的月结可以执行历史核验。');
            }
            $before = $this->generationSnapshot($settlement);
            $settlement->update([
                'generation_status' => 'not_applicable',
                'generated_at' => null,
            ]);
            $settlement->refresh();
            $this->audit->record(
                description: '月结历史生成状态已核验为不适用',
                properties: [
                    'settlement_id' => $settlement->id,
                    'recovery_action' => 'mark_not_applicable',
                    'recovery_basis' => $basis,
                    'before' => $before,
                    'after' => $this->generationSnapshot($settlement),
                ],
                causerId: $actorId,
                subject: $settlement,
                logName: 'settlement',
                event: 'generation_recovered',
                ipAddress: $ipAddress,
            );
        });
    }

    public function recoverUnverifiedWithBatch(int $settlementId, string $basis, int $actorId, ?string $ipAddress): void
    {
        $this->assertSuperAdmin($actorId);
        $basis = $this->normaliseRecoveryBasis($basis);

        DB::transaction(function () use ($settlementId, $basis, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if ($settlement->generation_status !== 'unverified' || ! in_array($settlement->status, ['pending_review', 'rejected'], true)) {
                throw new DomainException('只有待审核或已驳回的无法确认月结可以创建恢复批次。');
            }
            if ($settlement->settlement_run_id !== null) {
                throw new DomainException('该月结已有批次，请使用现有批次重试，不要重复创建恢复批次。');
            }

            $run = SettlementRun::query()
                ->whereDate('period_start', $settlement->period_start)
                ->whereDate('period_end', $settlement->period_end)
                ->lockForUpdate()
                ->first();
            if ($run === null) {
                $run = SettlementRun::query()->create([
                    'period_start' => $settlement->period_start,
                    'period_end' => $settlement->period_end,
                    'trigger_source' => 'recovery',
                    'status' => 'running',
                    'total_agents' => 1,
                    'progress_key' => 'settlement:recovery:'.Str::uuid(),
                    'initiated_by' => $actorId,
                    'started_at' => now(),
                ]);
            }
            $before = $this->generationSnapshot($settlement);
            $settlement->update(['settlement_run_id' => $run->id]);
            $this->generator->generate((string) $run->id, (int) $settlement->agent_id);
            $settlement->refresh();
            $this->audit->record(
                description: '月结已创建恢复批次并重新生成',
                properties: [
                    'settlement_id' => $settlement->id,
                    'recovery_action' => 'create_recovery_run',
                    'recovery_basis' => $basis,
                    'recovery_run_id' => $run->id,
                    'before' => $before,
                    'after' => $this->generationSnapshot($settlement),
                ],
                causerId: $actorId,
                subject: $settlement,
                logName: 'settlement',
                event: 'generation_recovered',
                ipAddress: $ipAddress,
            );
        });
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

    /** @param array<string, mixed> $properties */
    private function record(Settlement $settlement, string $description, string $event, int $actorId, ?string $ipAddress, array $properties = []): void
    {
        $this->audit->record(
            description: $description,
            properties: [
                'status' => $settlement->status,
                'settlement_id' => $settlement->id,
                ...$properties,
            ],
            causerId: $actorId,
            subject: $settlement,
            logName: 'settlement',
            event: $event,
            ipAddress: $ipAddress,
        );
    }

    private function normaliseRate(string $exchangeRate): BigDecimal
    {
        try {
            $rate = BigDecimal::of(trim($exchangeRate))->toScale(6, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new DomainException('结算汇率必须是有效数字。');
        }
        if ($rate->isLessThanOrEqualTo(0)) {
            throw new DomainException('结算汇率必须大于零。');
        }

        return $rate;
    }

    private function assertSuperAdmin(int $actorId): void
    {
        $actor = User::query()->find($actorId);
        if ($actor?->is_super_admin !== true) {
            throw new DomainException('只有超级管理员可以核验历史月结生成状态。');
        }
    }

    private function normaliseRecoveryBasis(string $basis): string
    {
        $basis = trim($basis);
        if ($basis === '') {
            throw new DomainException('历史月结核验必须填写依据。');
        }
        if (mb_strlen($basis) > 2000) {
            throw new DomainException('历史月结核验依据不能超过 2000 个字符。');
        }

        return $basis;
    }

    /** @return array{status: string, generation_status: string, generated_at: string|null, item_count: int, settlement_run_id: string|null} */
    private function generationSnapshot(Settlement $settlement): array
    {
        return [
            'status' => $settlement->status,
            'generation_status' => $settlement->generation_status,
            'generated_at' => $settlement->generated_at?->toIso8601String(),
            'item_count' => (int) $settlement->item_count,
            'settlement_run_id' => $settlement->settlement_run_id,
        ];
    }

    /** @return array{status: string, settled_on: string|null, settled_by: int|null, confirmed_at: string|null} */
    private function statusSnapshot(Settlement $settlement): array
    {
        return [
            'status' => $settlement->status,
            'settled_on' => $settlement->settled_on?->toDateString(),
            'settled_by' => $settlement->settled_by === null ? null : (int) $settlement->settled_by,
            'confirmed_at' => $settlement->confirmed_at?->toIso8601String(),
        ];
    }
}
