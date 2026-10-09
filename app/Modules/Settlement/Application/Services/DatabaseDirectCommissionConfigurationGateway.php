<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use App\Modules\Settlement\Infrastructure\Models\DirectCommissionRate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDirectCommissionConfigurationGateway implements DirectCommissionConfigurationGateway
{
    public function __construct(
        private AccessContextResolver $access,
        private AuditRecorder $audit,
    ) {}

    public function configuration(CarbonImmutable $date): array
    {
        return [
            'current' => $this->rateForDate($date),
            'history' => DirectCommissionRate::query()
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->get()
                ->map(fn (DirectCommissionRate $rate): array => $this->serialize($rate))
                ->values()
                ->all(),
        ];
    }

    public function rateForDate(CarbonImmutable $date): ?array
    {
        $rate = DirectCommissionRate::query()
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->latest('effective_from')
            ->latest('id')
            ->first();

        return $rate === null ? null : $this->serialize($rate);
    }

    public function saveRate(
        int $rateBps,
        CarbonImmutable $effectiveFrom,
        ?CarbonImmutable $effectiveUntil,
        string $reason,
        int $actorId,
        ?string $ipAddress,
    ): void {
        abort_unless($this->access->current()->isSuperAdmin(), 403);
        if ($rateBps < 0 || $rateBps > 10000) {
            throw new DomainException(__('settlements.direct_commission.errors.rate_out_of_range'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('settlements.direct_commission.errors.reason_required'));
        }

        $from = $effectiveFrom->startOfDay();
        $until = $effectiveUntil?->startOfDay();
        if ($until !== null && $until->lt($from)) {
            throw new DomainException(__('settlements.direct_commission.errors.period_invalid'));
        }
        DB::transaction(function () use ($rateBps, $from, $until, $reason, $actorId, $ipAddress): void {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('direct_commission_rates'))");
            $rates = DirectCommissionRate::query()->lockForUpdate()->orderBy('effective_from')->get();
            if ($rates->contains(fn (DirectCommissionRate $rate): bool => $rate->effective_from->isSameDay($from))) {
                throw new DomainException(__('settlements.direct_commission.errors.effective_date_exists'));
            }

            $previous = $rates->filter(fn (DirectCommissionRate $rate): bool => $rate->effective_from->lt($from)
                && ($rate->effective_until === null || $rate->effective_until->gte($from)))
                ->last();
            $next = $rates->first(fn (DirectCommissionRate $rate): bool => $rate->effective_from->gt($from));
            if ($previous !== null) {
                $previous->update(['effective_until' => $from->subDay()]);
            }
            $effectiveUntil = $until;
            if ($next !== null && ($effectiveUntil === null || $effectiveUntil->gte($next->effective_from))) {
                $effectiveUntil = $next->effective_from->subDay();
            }

            $rate = DirectCommissionRate::query()->create([
                'rate_bps' => $rateBps,
                'effective_from' => $from,
                'effective_until' => $effectiveUntil,
                'created_by' => $actorId,
                'reason' => $reason,
            ]);
            $this->audit->record(
                description: __('settlements.direct_commission.audit.rate_saved'),
                properties: ['after' => $this->serialize($rate), 'previous_id' => $previous?->id, 'next_id' => $next?->id],
                causerId: $actorId,
                subject: $rate,
                logName: 'direct-commission-configuration',
                event: 'rate_saved',
                ipAddress: $ipAddress,
            );
        });
    }

    /** @return array<string, mixed> */
    private function serialize(DirectCommissionRate $rate): array
    {
        return [
            'id' => (int) $rate->id,
            'rate_bps' => (int) $rate->rate_bps,
            'effective_from' => $rate->effective_from->toDateString(),
            'effective_until' => $rate->effective_until?->toDateString(),
            'reason' => (string) $rate->reason,
            'created_by' => $rate->created_by === null ? null : (int) $rate->created_by,
        ];
    }
}
