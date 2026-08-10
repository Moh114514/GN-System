<?php

namespace App\Modules\Settlement\Application\Services;

use App\Models\User;
use App\Modules\Agent\Application\Contracts\SettlementAgentGateway;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementGradeSuggestion;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
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
            throw new DomainException(__('settlements.errors.rejection_reason_required'));
        }
        $settlement = Settlement::query()->findOrFail($settlementId);
        if ($settlement->status !== 'pending_review') {
            throw new DomainException(__('settlements.errors.only_pending_review_reject'));
        }
        $settlement->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by' => $actorId,
            'reviewed_at' => now(),
        ]);
        $this->record($settlement, 'settlements.audit.rejected', 'rejected', $actorId, $ipAddress);
    }

    public function approve(int $settlementId, string $exchangeRate, int $actorId, ?string $ipAddress): void
    {
        $rate = $this->normaliseRate($exchangeRate);
        DB::transaction(function () use ($settlementId, $rate, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if (! in_array($settlement->status, ['pending_review', 'rejected'], true)) {
                throw new DomainException(__('settlements.errors.invalid_approval_status'));
            }
            if ($settlement->generation_status !== 'generated') {
                throw new DomainException(__('settlements.errors.generation_required'));
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
            $this->record($settlement, 'settlements.audit.approved', 'approved', $actorId, $ipAddress, [
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
                throw new DomainException(__('settlements.errors.only_approved_settle'));
            }
            $settlement->update([
                'status' => 'settled',
                'settled_on' => now()->toDateString(),
                'settled_by' => $actorId,
                'confirmed_at' => now(),
            ]);
            $this->record($settlement, 'settlements.audit.settled', 'settled', $actorId, $ipAddress);
        });
    }

    public function correctStatus(int $settlementId, string $targetStatus, string $reason, int $actorId, ?string $ipAddress): void
    {
        $targetStatus = trim($targetStatus);
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('settlements.errors.correction_reason_required'));
        }
        if (! in_array($targetStatus, ['pending_review', 'approved', 'settled'], true)) {
            throw new DomainException(__('settlements.errors.invalid_correction_status'));
        }

        DB::transaction(function () use ($settlementId, $targetStatus, $reason, $actorId, $ipAddress): void {
            $settlement = Settlement::query()->lockForUpdate()->findOrFail($settlementId);
            if (! in_array($settlement->status, ['approved', 'settled'], true)) {
                throw new DomainException(__('settlements.errors.correction_not_allowed'));
            }
            if ($settlement->status === $targetStatus) {
                throw new DomainException(__('settlements.errors.same_correction_status'));
            }

            $before = $this->statusSnapshot($settlement);
            $itemsRemoved = 0;
            $documentsRemoved = 0;
            if ($targetStatus === 'pending_review') {
                if (SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->where('status', 'accepted')->exists()) {
                    throw new DomainException(__('settlements.errors.accepted_grade_blocks_correction'));
                }
                $itemsRemoved = DB::table('settlement_items')->where('settlement_id', $settlement->id)->delete();
                $documentsRemoved = $this->documents->discard((int) $settlement->id);
                SettlementGradeSuggestion::query()->where('settlement_id', $settlement->id)->delete();
                SettlementRunMember::query()
                    ->where('settlement_id', $settlement->id)
                    ->where('outcome', 'generated')
                    ->update([
                        'settlement_id' => null,
                        'outcome' => 'pending',
                        'error_message_key' => null,
                        'error_parameters' => null,
                        'processed_at' => null,
                    ]);
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
                description: __('settlements.audit.status_corrected'),
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
                messageKey: 'settlements.audit.status_corrected',
            );
        });
    }

    public function regenerateDocuments(int $settlementId): void
    {
        $settlement = Settlement::query()->findOrFail($settlementId);
        if (! in_array($settlement->status, ['approved', 'settled'], true)) {
            throw new DomainException(__('settlements.errors.only_approved_documents'));
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
                throw new DomainException(__('settlements.errors.only_unverified_recovery'));
            }
            $before = $this->generationSnapshot($settlement);
            $settlement->update([
                'generation_status' => 'not_applicable',
                'generated_at' => null,
            ]);
            $settlement->refresh();
            $this->audit->record(
                description: __('settlements.audit.generation_recovered'),
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
                messageKey: 'settlements.audit.generation_recovered',
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
                throw new DomainException(__('settlements.errors.invalid_recovery_status'));
            }
            if ($settlement->settlement_run_id !== null) {
                throw new DomainException(__('settlements.errors.recovery_batch_exists'));
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
                description: __('settlements.audit.recovery_batch_created'),
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
                messageKey: 'settlements.audit.recovery_batch_created',
            );
        });
    }

    public function reviewSuggestion(int $suggestionId, bool $accept, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($suggestionId, $accept, $reason, $actorId): void {
            $suggestion = SettlementGradeSuggestion::query()->lockForUpdate()->findOrFail($suggestionId);
            if ($suggestion->status !== 'pending') {
                throw new DomainException(__('settlements.errors.grade_suggestion_processed'));
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
    private function record(Settlement $settlement, string $messageKey, string $event, int $actorId, ?string $ipAddress, array $properties = []): void
    {
        $this->audit->record(
            description: __($messageKey),
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
            messageKey: $messageKey,
        );
    }

    private function normaliseRate(string $exchangeRate): BigDecimal
    {
        try {
            $rate = BigDecimal::of(trim($exchangeRate))->toScale(6, RoundingMode::HalfUp);
        } catch (MathException) {
            throw new DomainException(__('settlements.errors.invalid_exchange_rate'));
        }
        if ($rate->isLessThanOrEqualTo(0)) {
            throw new DomainException(__('settlements.errors.positive_exchange_rate'));
        }

        return $rate;
    }

    private function assertSuperAdmin(int $actorId): void
    {
        $actor = User::query()->find($actorId);
        if ($actor?->is_super_admin !== true) {
            throw new DomainException(__('settlements.errors.super_admin_recovery_only'));
        }
    }

    private function normaliseRecoveryBasis(string $basis): string
    {
        $basis = trim($basis);
        if ($basis === '') {
            throw new DomainException(__('settlements.errors.recovery_basis_required'));
        }
        if (mb_strlen($basis) > 2000) {
            throw new DomainException(__('settlements.errors.recovery_basis_too_long'));
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
