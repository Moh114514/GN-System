<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Application\Contracts\InternalUserReferenceReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Order\Application\Contracts\ReportOrderReader;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class DirectTeamOverviewService
{
    public function __construct(
        private AccessContextResolver $access,
        private InternalUserReferenceReader $users,
        private ReportUserReader $userNames,
        private ReportOrderReader $orders,
        private BusinessClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(string $month, ?int $requestedOwnerId = null): array
    {
        $context = $this->access->current();
        abort_unless($context->isSuperAdmin() || $context->isDirectCustomerManager(), 403);
        $month = $this->normalizeMonth($month);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', (string) config('app.timezone'));
        $to = $from->endOfMonth();

        $eligibleOwners = $context->isSuperAdmin()
            ? $this->users->eligibleDirectCustomerManagers()
            : ($context->userId === null ? [] : [['id' => $context->userId, 'name' => '']]);
        $allowedOwnerIds = array_values(array_unique(array_map(
            static fn (array $user): int => (int) $user['id'],
            $eligibleOwners,
        )));
        if ($context->isDirectCustomerManager() && $context->userId !== null) {
            $allowedOwnerIds = [$context->userId];
        }
        if ($requestedOwnerId !== null && ! in_array($requestedOwnerId, $allowedOwnerIds, true)) {
            abort(404);
        }
        $ownerIds = $requestedOwnerId === null ? $allowedOwnerIds : [$requestedOwnerId];
        $stats = $this->orders->directTeamOverview($ownerIds, $from, $to);
        $names = $this->userNames->namesByIds($ownerIds);
        $rows = [];
        foreach ($ownerIds as $ownerId) {
            $owner = $stats['owners'][$ownerId] ?? ['orders' => 0, 'amount_krw' => 0, 'customers' => 0];
            $rows[] = [
                'id' => $ownerId,
                'name' => $names[$ownerId] ?? __('customers.fallback.unknown_owner'),
                ...$owner,
            ];
        }

        return [
            'month' => $month,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'selected_owner_id' => $requestedOwnerId,
            'owner_options' => $eligibleOwners,
            'owners' => $rows,
            'totals' => [
                'orders' => (int) $stats['orders'],
                'amount_krw' => (int) $stats['amount_krw'],
                'customers' => (int) $stats['customers'],
            ],
            'can_filter_owner' => $context->isSuperAdmin(),
        ];
    }

    public function currentMonth(): string
    {
        return $this->clock->now()->format('Y-m');
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (preg_match('/^(\d{4})-(\d{2})$/D', $month, $parts) !== 1
            || ! checkdate((int) $parts[2], 1, (int) $parts[1])) {
            throw new DomainException(__('direct_team.errors.invalid_month'));
        }

        return $month;
    }
}
