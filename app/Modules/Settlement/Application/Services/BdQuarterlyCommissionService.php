<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Order\Application\Contracts\BdCommissionOrderReader;
use App\Modules\Settlement\Application\Contracts\BdCommissionCorrectionGateway;
use App\Modules\Settlement\Application\Data\BdCommissionOrderData;
use App\Modules\Settlement\Infrastructure\Models\BdCommissionAdjustment;
use App\Modules\Settlement\Infrastructure\Models\BdCommissionRule;
use App\Modules\Settlement\Infrastructure\Models\BdQuarterlyCommission;
use App\Modules\Settlement\Infrastructure\Models\BdQuarterlyCommissionItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class BdQuarterlyCommissionService implements BdCommissionCorrectionGateway
{
    public function __construct(
        private BdCommissionOrderReader $orders,
        private AccessContextResolver $access,
        private InternalUserReferenceReader $users,
        private AuditRecorder $audit,
        private AgentReferenceReader $agents,
    ) {}

    /** @return array{start: CarbonImmutable, end: CarbonImmutable} */
    public function quarter(CarbonImmutable $date): array
    {
        $start = $date->startOfQuarter()->startOfDay();

        return ['start' => $start, 'end' => $start->addMonths(3)->subDay()->startOfDay()];
    }

    /** @return array<string, mixed> */
    public function preview(CarbonImmutable $quarterStart): array
    {
        $this->assertReadAccess();
        $period = $this->period($quarterStart);

        return $period?->status === 'confirmed'
            ? $this->storedCalculation($period)
            : $this->calculate($quarterStart, $period);
    }

    public function saveRule(
        string $baseType,
        string $currency,
        int $rateBps,
        CarbonImmutable $effectiveFrom,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): BdCommissionRule {
        $this->assertAdmin();
        if ($baseType !== 'order_amount_krw' || strtoupper($currency) !== 'KRW') {
            throw new DomainException(__('settlements.bd_commission.errors.rule_basis_unsupported'));
        }
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new DomainException(__('settlements.errors.rate_out_of_range'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('settlements.bd_commission.errors.rule_reason_required'));
        }
        if (BdCommissionRule::query()->whereDate('effective_from', $effectiveFrom->toDateString())->exists()) {
            throw new DomainException(__('settlements.bd_commission.errors.rule_version_exists'));
        }

        $rule = BdCommissionRule::query()->create([
            'base_type' => $baseType,
            'currency' => 'KRW',
            'rate_bps' => $rateBps,
            'effective_from' => $effectiveFrom->toDateString(),
            'created_by' => $actorId,
            'reason' => $reason,
            'metadata' => ['formula' => 'order_amount_krw * rate_bps / 10000, half_up'],
        ]);
        $this->audit->record(
            description: __('settlements.bd_commission.audit.rule_created'),
            properties: $rule->only(['base_type', 'currency', 'rate_bps', 'effective_from', 'reason']),
            causerId: $actorId,
            subject: $rule,
            logName: 'bd-commission',
            event: 'rule_created',
            ipAddress: $ipAddress,
        );

        return $rule;
    }

    /** @return Collection<int, BdCommissionRule> */
    public function rules(): Collection
    {
        $this->assertAdmin();

        return BdCommissionRule::query()->orderByDesc('effective_from')->orderByDesc('id')->get();
    }

    /** @return list<array{id: int, name: string}> */
    public function eligibleBdUsers(): array
    {
        $this->assertAdmin();

        return $this->users->eligibleUsers();
    }

    public function generate(CarbonImmutable $quarterStart, int $actorId, ?string $ipAddress): BdQuarterlyCommission
    {
        $this->assertAdmin();

        return DB::transaction(function () use ($quarterStart, $actorId, $ipAddress): BdQuarterlyCommission {
            $period = $this->period($quarterStart, true);
            if ($period !== null && in_array($period->status, ['reviewed', 'confirmed'], true)) {
                throw new DomainException(__('settlements.bd_commission.errors.period_locked'));
            }
            $calculation = $this->calculate($quarterStart, $period);
            $bounds = $this->quarter($quarterStart);
            $period ??= BdQuarterlyCommission::query()->create([
                'quarter_start' => $bounds['start']->toDateString(),
                'quarter_end' => $bounds['end']->toDateString(),
                'status' => 'draft',
                'currency' => 'KRW',
            ]);
            $period->items()->delete();
            foreach ($calculation['items'] as $item) {
                BdQuarterlyCommissionItem::query()->create([
                    'quarterly_commission_id' => $period->id,
                    ...$item,
                ]);
            }
            $period->forceFill([
                'status' => 'generated',
                'currency' => 'KRW',
                'total_basis_krw' => $calculation['basis_krw'],
                'total_adjustment_krw' => $calculation['adjustment_krw'],
                'total_commission_krw' => $calculation['total_commission_krw'],
                'rule_snapshot' => $calculation['rule_snapshots'],
                'generated_by' => $actorId,
                'generated_at' => now(),
            ])->save();
            $this->audit->record(
                description: __('settlements.bd_commission.audit.generated'),
                properties: [
                    'quarter_start' => $period->quarter_start->format('Y-m-d'),
                    'quarter_end' => $period->quarter_end->format('Y-m-d'),
                    'item_count' => count($calculation['items']),
                    'total_commission_krw' => $calculation['total_commission_krw'],
                ],
                causerId: $actorId,
                subject: $period,
                logName: 'bd-commission',
                event: 'generated',
                ipAddress: $ipAddress,
            );

            return $period->fresh();
        });
    }

    public function review(int $periodId, int $actorId, ?string $ipAddress): void
    {
        $this->assertAdmin();
        $period = BdQuarterlyCommission::query()->lockForUpdate()->findOrFail($periodId);
        if ($period->status !== 'generated') {
            throw new DomainException(__('settlements.bd_commission.errors.review_status_invalid'));
        }
        $period->update(['status' => 'reviewed', 'reviewed_by' => $actorId, 'reviewed_at' => now()]);
        $this->recordLifecycleAudit($period, 'reviewed', $actorId, $ipAddress, __('settlements.bd_commission.audit.reviewed'));
    }

    public function confirm(int $periodId, int $actorId, ?string $ipAddress): void
    {
        $this->assertAdmin();
        $period = BdQuarterlyCommission::query()->lockForUpdate()->findOrFail($periodId);
        if ($period->status !== 'reviewed') {
            throw new DomainException(__('settlements.bd_commission.errors.confirm_status_invalid'));
        }
        $period->update(['status' => 'confirmed', 'confirmed_by' => $actorId, 'confirmed_at' => now()]);
        $this->recordLifecycleAudit($period, 'confirmed', $actorId, $ipAddress, __('settlements.bd_commission.audit.confirmed'));
    }

    public function addAdjustment(
        int $periodId,
        ?int $bdUserId,
        int $amountKrw,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): BdCommissionAdjustment {
        $this->assertAdmin();
        $reason = trim($reason);
        if ($amountKrw === 0) {
            throw new DomainException(__('settlements.bd_commission.errors.adjustment_nonzero'));
        }
        if ($reason === '') {
            throw new DomainException(__('settlements.bd_commission.errors.adjustment_reason_required'));
        }
        $period = BdQuarterlyCommission::query()->lockForUpdate()->findOrFail($periodId);
        if ($period->status === 'confirmed') {
            throw new DomainException(__('settlements.bd_commission.errors.period_locked'));
        }
        if ($bdUserId !== null && ! $this->users->isEligible($bdUserId)) {
            throw new DomainException(__('settlements.bd_commission.errors.bd_invalid'));
        }
        $adjustment = $period->adjustments()->create([
            'bd_user_id' => $bdUserId,
            'amount_krw' => $amountKrw,
            'currency' => 'KRW',
            'source' => 'manual',
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
        $this->refreshTotals($period);
        $this->audit->record(
            description: __('settlements.bd_commission.audit.adjusted'),
            properties: ['amount_krw' => $amountKrw, 'bd_user_id' => $bdUserId, 'reason' => $reason],
            causerId: $actorId,
            subject: $adjustment,
            logName: 'bd-commission',
            event: 'adjusted',
            ipAddress: $ipAddress,
        );

        return $adjustment;
    }

    /** @return Collection<int, BdQuarterlyCommission> */
    public function visiblePeriods(): Collection
    {
        $this->assertReadAccess();
        $context = $this->access->current();

        $periods = BdQuarterlyCommission::query()
            ->when(! $context->isSuperAdmin(), function ($query) use ($context): void {
                $query->where(function ($scope) use ($context): void {
                    $scope->whereHas('items', fn ($items) => $items->where('bd_user_id', $context->userId))
                        ->orWhereHas('adjustments', fn ($adjustments) => $adjustments->where('bd_user_id', $context->userId));
                });
            })
            ->withCount(['items', 'adjustments'])
            ->orderByDesc('quarter_start')
            ->get();

        if (! $context->isSuperAdmin() && $context->userId !== null) {
            $periods->each(function (BdQuarterlyCommission $period) use ($context): void {
                $this->scopePeriodTotals($period, $context->userId);
            });
        }

        return $periods;
    }

    /**
     * @return array{
     *     period: BdQuarterlyCommission,
     *     items: list<array{order_id: int, bd_user_id: int|null, bd_name: string, occurred_on: string, business_group_id: int|null, business_group_name: string, agent_name: string, basis_krw: int, rate_bps: int, commission_krw: int}>,
     *     adjustments: list<array{bd_user_id: int|null, bd_name: string, amount_krw: int, source: string, reason: string}>
     * }
     */
    public function visibleDetail(int $periodId, ?int $bdUserId = null): array
    {
        $this->assertExportOrReadAccess($bdUserId !== null);
        $period = BdQuarterlyCommission::query()->with(['items', 'adjustments'])->findOrFail($periodId);
        $context = $this->access->current();
        $targetBdUserId = $bdUserId;
        if (! $context->isSuperAdmin()) {
            if ($targetBdUserId !== null && $targetBdUserId !== $context->userId) {
                abort(403);
            }
            $targetBdUserId = $context->userId;
        }
        if ($targetBdUserId !== null) {
            $period->setRelation('items', $period->items->where('bd_user_id', $targetBdUserId)->values());
            $period->setRelation('adjustments', $period->adjustments->where('bd_user_id', $targetBdUserId)->values());
            abort_if($period->items->isEmpty() && $period->adjustments->isEmpty(), 403);
            $this->scopePeriodTotals($period, $targetBdUserId);
        }
        $names = collect($this->users->eligibleUsers())->keyBy('id');
        $agentIds = $period->items
            ->map(fn (BdQuarterlyCommissionItem $item): mixed => data_get($item->attribution_snapshot, 'business_group.agent_id'))
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $agentReferences = $agentIds === [] ? [] : $this->agents->agentsByIds($agentIds);

        return [
            'period' => $period,
            'items' => $period->items->map(fn (BdQuarterlyCommissionItem $item): array => [
                'order_id' => (int) $item->order_id,
                'bd_user_id' => $item->bd_user_id === null ? null : (int) $item->bd_user_id,
                'bd_name' => $names->get((int) $item->bd_user_id)['name']
                    ?? data_get($item->attribution_snapshot, 'business_group.bd_manager.user_name', __('settlements.bd_commission.unknown_bd')),
                'occurred_on' => $item->occurred_on->format('Y-m-d'),
                'business_group_id' => $groupId = data_get($item->attribution_snapshot, 'business_group.business_group_id'),
                'business_group_name' => (string) (data_get($item->attribution_snapshot, 'business_group.business_group_name')
                    ?: ($groupId === null ? __('settlements.bd_commission.unknown_group') : __('settlements.bd_commission.business_group_id', ['id' => $groupId]))),
                'agent_name' => (string) (data_get($item->attribution_snapshot, 'agent.name')
                    ?: ($agentReferences[(int) data_get($item->attribution_snapshot, 'business_group.agent_id')]['name']
                        ?? (data_get($item->attribution_snapshot, 'business_group.agent_id') !== null
                            ? __('settlements.bd_commission.agent_id', ['id' => data_get($item->attribution_snapshot, 'business_group.agent_id')])
                            : __('settlements.bd_commission.unknown_agent')))),
                'basis_krw' => (int) $item->basis_krw,
                'rate_bps' => (int) $item->rate_bps,
                'commission_krw' => (int) $item->commission_krw,
            ])->all(),
            'adjustments' => $period->adjustments->map(fn (BdCommissionAdjustment $adjustment): array => [
                'bd_user_id' => $adjustment->bd_user_id === null ? null : (int) $adjustment->bd_user_id,
                'bd_name' => $names->get((int) $adjustment->bd_user_id)['name'] ?? __('settlements.bd_commission.unknown_bd'),
                'amount_krw' => (int) $adjustment->amount_krw,
                'source' => (string) $adjustment->source,
                'reason' => (string) $adjustment->reason,
            ])->all(),
        ];
    }

    public function onOrderCorrected(
        BdCommissionOrderData $before,
        ?BdCommissionOrderData $after,
        int $actorId,
        ?string $ipAddress,
    ): void {
        $item = BdQuarterlyCommissionItem::query()
            ->with('quarterlyCommission')
            ->where('order_id', $before->orderId)
            ->whereHas('quarterlyCommission', fn ($query) => $query->where('status', 'confirmed'))
            ->first();
        if ($item === null || ! $item->quarterlyCommission instanceof BdQuarterlyCommission) {
            return;
        }

        DB::transaction(function () use ($item, $after, $actorId, $ipAddress): void {
            $sourcePeriod = $item->quarterlyCommission;
            $sourceStart = CarbonImmutable::parse($sourcePeriod->quarter_start);
            $targetStart = $sourceStart->addMonths(3)->startOfQuarter();
            $target = $this->draftPeriod($targetStart);
            $new = $after === null ? null : $this->contribution($after);
            $sameQuarter = $after !== null
                && $after->occurredOn->startOfQuarter()->isSameDay($sourceStart->startOfQuarter());

            $this->createCorrectionAdjustment(
                target: $target,
                bdUserId: $this->snapshotBdId($item->attribution_snapshot),
                amountKrw: -((int) $item->commission_krw),
                sourcePeriod: $sourcePeriod,
                orderId: (int) $item->order_id,
                reason: __('settlements.bd_commission.correction_reason'),
                actorId: $actorId,
            );
            if ($sameQuarter && $new !== null) {
                $this->createCorrectionAdjustment(
                    target: $target,
                    bdUserId: $new['bd_user_id'],
                    amountKrw: $new['commission_krw'],
                    sourcePeriod: $sourcePeriod,
                    orderId: (int) $item->order_id,
                    reason: __('settlements.bd_commission.correction_reason'),
                    actorId: $actorId,
                );
            }
            $this->refreshTotals($target);
            $this->audit->record(
                description: __('settlements.bd_commission.audit.corrected'),
                properties: [
                    'source_period_id' => $sourcePeriod->id,
                    'target_period_id' => $target->id,
                    'order_id' => $item->order_id,
                    'new_commission_krw' => $new['commission_krw'] ?? null,
                ],
                causerId: $actorId,
                subject: $target,
                logName: 'bd-commission',
                event: 'order_correction',
                ipAddress: $ipAddress,
            );
        });
    }

    /** @return array<string, mixed> */
    private function calculate(CarbonImmutable $quarterStart, ?BdQuarterlyCommission $period): array
    {
        $bounds = $this->quarter($quarterStart);
        $items = [];
        $seen = [];
        $basis = 0;
        $commission = 0;
        $ruleSnapshots = [];
        foreach ($this->orders->completedBetween($bounds['start'], $bounds['end']) as $order) {
            if (isset($seen[$order->orderId])) {
                continue;
            }
            $seen[$order->orderId] = true;
            $attribution = $this->attribution($order->attributionSnapshot);
            if ($attribution['bd_user_id'] === null) {
                throw new DomainException(__('settlements.bd_commission.errors.attribution_missing', ['order_id' => $order->orderId]));
            }
            $rule = $this->ruleFor($order->occurredOn);
            if ($rule === null) {
                throw new DomainException(__('settlements.bd_commission.errors.rule_missing', ['date' => $order->occurredOn->toDateString()]));
            }
            $amount = $this->calculateAmount($order->amountKrw, (int) $rule->rate_bps);
            $ruleSnapshot = [
                'id' => (int) $rule->id,
                'base_type' => (string) $rule->base_type,
                'currency' => 'KRW',
                'rate_bps' => (int) $rule->rate_bps,
                'effective_from' => $rule->effective_from->format('Y-m-d'),
                'formula' => 'order_amount_krw * rate_bps / 10000, half_up',
            ];
            $ruleSnapshots[(int) $rule->id] = $ruleSnapshot;
            $items[] = [
                'order_id' => $order->orderId,
                'bd_user_id' => $attribution['bd_user_id'],
                'business_group_id' => $attribution['business_group_id'],
                'occurred_on' => $order->occurredOn->toDateString(),
                'basis_krw' => $order->amountKrw,
                'rate_bps' => (int) $rule->rate_bps,
                'commission_krw' => $amount,
                'currency' => 'KRW',
                'attribution_snapshot' => $order->attributionSnapshot ?? [],
                'rule_snapshot' => $ruleSnapshot,
            ];
            $basis += $order->amountKrw;
            $commission += $amount;
        }
        $adjustment = $this->adjustmentTotal($period);

        return [
            'period_start' => $bounds['start']->toDateString(),
            'period_end' => $bounds['end']->toDateString(),
            'basis_krw' => $basis,
            'commission_krw' => $commission,
            'adjustment_krw' => $adjustment,
            'total_commission_krw' => $commission + $adjustment,
            'item_count' => count($items),
            'items' => $items,
            'rule_snapshots' => array_values($ruleSnapshots),
        ];
    }

    /** @return array<string, mixed> */
    private function storedCalculation(BdQuarterlyCommission $period): array
    {
        $detail = $this->visibleDetail((int) $period->id);
        $items = $detail['items'];
        $adjustment = array_sum(array_column($detail['adjustments'], 'amount_krw'));

        return [
            'period_start' => $period->quarter_start->format('Y-m-d'),
            'period_end' => $period->quarter_end->format('Y-m-d'),
            'basis_krw' => array_sum(array_column($items, 'basis_krw')),
            'commission_krw' => array_sum(array_column($items, 'commission_krw')),
            'adjustment_krw' => $adjustment,
            'total_commission_krw' => array_sum(array_column($items, 'commission_krw')) + $adjustment,
            'item_count' => count($items),
            'items' => $items,
            'rule_snapshots' => $period->rule_snapshot ?? [],
        ];
    }

    private function period(CarbonImmutable $quarterStart, bool $lock = false): ?BdQuarterlyCommission
    {
        $bounds = $this->quarter($quarterStart);
        $query = BdQuarterlyCommission::query()
            ->whereDate('quarter_start', $bounds['start']->toDateString())
            ->whereDate('quarter_end', $bounds['end']->toDateString());

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function draftPeriod(CarbonImmutable $quarterStart): BdQuarterlyCommission
    {
        $candidateStart = $quarterStart->startOfQuarter();
        while (true) {
            $existing = $this->period($candidateStart, true);
            if ($existing === null) {
                $bounds = $this->quarter($candidateStart);

                return BdQuarterlyCommission::query()->create([
                    'quarter_start' => $bounds['start']->toDateString(),
                    'quarter_end' => $bounds['end']->toDateString(),
                    'status' => 'draft',
                    'currency' => 'KRW',
                ]);
            }
            if ($existing->status !== 'confirmed') {
                return $existing;
            }

            $candidateStart = $candidateStart->addMonths(3)->startOfQuarter();
        }
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     * @return array{bd_user_id: int|null, business_group_id: int|null}
     */
    private function attribution(?array $snapshot): array
    {
        $group = is_array($snapshot['business_group'] ?? null) ? $snapshot['business_group'] : [];
        $bd = is_array($group['bd_manager'] ?? null) ? $group['bd_manager'] : [];

        return [
            'bd_user_id' => isset($bd['user_id']) && (int) $bd['user_id'] > 0 ? (int) $bd['user_id'] : null,
            'business_group_id' => isset($group['business_group_id']) && (int) $group['business_group_id'] > 0 ? (int) $group['business_group_id'] : null,
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotBdId(array $snapshot): ?int
    {
        return $this->attribution($snapshot)['bd_user_id'];
    }

    /** @return array{bd_user_id: int|null, business_group_id: int|null, commission_krw: int} */
    private function contribution(BdCommissionOrderData $order): array
    {
        if (! $order->active) {
            return ['bd_user_id' => null, 'business_group_id' => null, 'commission_krw' => 0];
        }
        $attribution = $this->attribution($order->attributionSnapshot);
        if ($attribution['bd_user_id'] === null) {
            throw new DomainException(__('settlements.bd_commission.errors.attribution_missing', ['order_id' => $order->orderId]));
        }
        $rule = $this->ruleFor($order->occurredOn);
        if ($rule === null) {
            throw new DomainException(__('settlements.bd_commission.errors.rule_missing', ['date' => $order->occurredOn->toDateString()]));
        }

        return [
            ...$attribution,
            'commission_krw' => $this->calculateAmount($order->amountKrw, (int) $rule->rate_bps),
        ];
    }

    private function ruleFor(CarbonImmutable $date): ?BdCommissionRule
    {
        return BdCommissionRule::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    private function calculateAmount(int $basis, int $rateBps): int
    {
        return BigDecimal::of($basis)
            ->multipliedBy($rateBps)
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    private function createCorrectionAdjustment(
        BdQuarterlyCommission $target,
        ?int $bdUserId,
        int $amountKrw,
        BdQuarterlyCommission $sourcePeriod,
        int $orderId,
        string $reason,
        int $actorId,
    ): void {
        if ($amountKrw === 0) {
            return;
        }
        $target->adjustments()->create([
            'bd_user_id' => $bdUserId,
            'amount_krw' => $amountKrw,
            'currency' => 'KRW',
            'source' => 'order_correction',
            'source_order_id' => $orderId,
            'source_quarterly_commission_id' => $sourcePeriod->id,
            'reason' => $reason,
            'created_by' => $actorId,
        ]);
    }

    private function refreshTotals(BdQuarterlyCommission $period): void
    {
        $items = $period->items()->selectRaw('COALESCE(SUM(basis_krw), 0) AS basis, COALESCE(SUM(commission_krw), 0) AS commission')->first();
        $adjustment = (int) $period->adjustments()->sum('amount_krw');
        $period->update([
            'total_basis_krw' => (int) ($items->basis ?? 0),
            'total_adjustment_krw' => $adjustment,
            'total_commission_krw' => (int) ($items->commission ?? 0) + $adjustment,
        ]);
    }

    private function adjustmentTotal(?BdQuarterlyCommission $period): int
    {
        if ($period === null) {
            return 0;
        }
        $query = $period->adjustments();
        $context = $this->access->current();
        if (! $context->isSuperAdmin() && $context->userId !== null) {
            $query->where('bd_user_id', $context->userId);
        }

        return (int) $query->sum('amount_krw');
    }

    private function scopePeriodTotals(BdQuarterlyCommission $period, int $userId): void
    {
        $items = $period->items()->where('bd_user_id', $userId)
            ->selectRaw('COALESCE(SUM(basis_krw), 0) AS basis, COALESCE(SUM(commission_krw), 0) AS commission')
            ->first();
        $adjustment = (int) $period->adjustments()->where('bd_user_id', $userId)->sum('amount_krw');
        $period->forceFill([
            'items_count' => (int) $period->items()->where('bd_user_id', $userId)->count(),
            'adjustments_count' => (int) $period->adjustments()->where('bd_user_id', $userId)->count(),
            'total_basis_krw' => (int) ($items->basis ?? 0),
            'total_adjustment_krw' => $adjustment,
            'total_commission_krw' => (int) ($items->commission ?? 0) + $adjustment,
        ]);
    }

    private function recordLifecycleAudit(BdQuarterlyCommission $period, string $event, int $actorId, ?string $ipAddress, string $description): void
    {
        $this->audit->record(
            description: $description,
            properties: ['period_id' => $period->id, 'status' => $event],
            causerId: $actorId,
            subject: $period,
            logName: 'bd-commission',
            event: $event,
            ipAddress: $ipAddress,
        );
    }

    private function assertReadAccess(): void
    {
        $context = $this->access->current();
        abort_unless($context->isSuperAdmin() || $context->isBdManager(), 403);
    }

    private function assertExportOrReadAccess(bool $export): void
    {
        if ($export) {
            $context = $this->access->current();
            abort_unless($context->isSuperAdmin() || $context->isBdManager(), 403);

            return;
        }

        $this->assertReadAccess();
    }

    private function assertAdmin(): void
    {
        abort_unless($this->access->current()->isSuperAdmin(), 403);
    }
}
