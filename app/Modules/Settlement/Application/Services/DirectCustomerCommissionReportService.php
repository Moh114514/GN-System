<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Settlement\Application\Contracts\DirectOrderCommissionGateway;
use Carbon\CarbonImmutable;

final readonly class DirectCustomerCommissionReportService
{
    public function __construct(
        private DirectOrderCommissionGateway $commissions,
        private AccessContextResolver $access,
        private InternalUserReferenceReader $users,
        private ReportUserReader $names,
    ) {}

    /**
     * @return array{
     *     records: list<array<string, mixed>>,
     *     summaries: list<array{owner_id: int, owner: string, orders: int, order_amount_krw: int, commission_amount_krw: int, rates: array<int, bool>}>,
     *     totals: array{orders: int, order_amount_krw: int, commission_amount_krw: int},
     *     owner_options: list<array<string, mixed>>
     * }
     */
    public function snapshot(CarbonImmutable $from, CarbonImmutable $to, ?int $requestedOwnerId): array
    {
        $context = $this->access->current();
        $eligibleOwners = $this->users->eligibleDirectCustomerManagers();
        $eligibleIds = array_map(static fn (array $user): int => (int) $user['id'], $eligibleOwners);
        if ($context->hasOrganizationWideReadAccess() && $requestedOwnerId !== null && ! in_array($requestedOwnerId, $eligibleIds, true)) {
            abort(404);
        }
        if ($context->isDirectCustomerManager() && $requestedOwnerId !== null && $requestedOwnerId !== $context->userId) {
            abort(404);
        }

        $records = $this->commissions->recordsForViewer($from, $to, $requestedOwnerId);
        $ownerIds = array_values(array_unique(array_filter(array_map(
            static fn (array $record): ?int => $record['owner_id'],
            $records,
        ))));
        if ($context->isDirectCustomerManager() && $context->userId !== null) {
            $ownerIds[] = $context->userId;
            $ownerIds = array_values(array_unique($ownerIds));
        } elseif ($requestedOwnerId !== null) {
            $ownerIds[] = $requestedOwnerId;
            $ownerIds = array_values(array_unique($ownerIds));
        } else {
            $ownerIds = array_values(array_unique([...$eligibleIds, ...$ownerIds]));
        }

        $ownerNames = $this->names->namesByIds($ownerIds);
        $summaries = [];
        foreach ($ownerIds as $ownerId) {
            $summaries[$ownerId] = $this->emptySummary($ownerId, $ownerNames[$ownerId] ?? __('settlements.direct_commission.unknown_owner'));
        }
        foreach ($records as $record) {
            if ($record['status'] !== 'active' || $record['owner_id'] === null) {
                continue;
            }
            $ownerId = (int) $record['owner_id'];
            $summaries[$ownerId] ??= $this->emptySummary(
                $ownerId,
                $ownerNames[$ownerId] ?? __('settlements.direct_commission.unknown_owner'),
            );
            $summaries[$ownerId]['orders']++;
            $summaries[$ownerId]['order_amount_krw'] += (int) $record['order_amount_krw'];
            $summaries[$ownerId]['commission_amount_krw'] += (int) $record['commission_amount_krw'];
            $summaries[$ownerId]['rates'][(int) $record['rate_bps']] = true;
        }

        $summaries = array_values($summaries);
        usort($summaries, static fn (array $left, array $right): int => [$left['owner'], $left['owner_id']] <=> [$right['owner'], $right['owner_id']]);

        return [
            'records' => $records,
            'summaries' => $summaries,
            'totals' => [
                'orders' => array_sum(array_column($summaries, 'orders')),
                'order_amount_krw' => array_sum(array_column($summaries, 'order_amount_krw')),
                'commission_amount_krw' => array_sum(array_column($summaries, 'commission_amount_krw')),
            ],
            'owner_options' => $context->hasOrganizationWideReadAccess() ? $eligibleOwners : [],
        ];
    }

    /**
     * @return array{owner_id: int, owner: string, orders: int, order_amount_krw: int, commission_amount_krw: int, rates: array<int, bool>}
     */
    private function emptySummary(int $ownerId, string $owner): array
    {
        return [
            'owner_id' => $ownerId,
            'owner' => $owner,
            'orders' => 0,
            'order_amount_krw' => 0,
            'commission_amount_krw' => 0,
            'rates' => [],
        ];
    }
}
