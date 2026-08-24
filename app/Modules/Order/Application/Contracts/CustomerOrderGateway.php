<?php

namespace App\Modules\Order\Application\Contracts;

use App\Modules\Order\Application\Data\CustomerAppointmentData;
use Carbon\CarbonImmutable;

interface CustomerOrderGateway
{
    public function createInitialAppointment(CustomerAppointmentData $data): int;

    /** @return array<int, int> */
    public function customerIdsForInstitution(int $institutionId): array;

    /** @return array<int, array<string, mixed>> */
    public function timelineForCustomer(int $customerId): array;

    public function hasAnyOrder(int $customerId): bool;

    public function transferFutureAppointments(int $customerId, int $ownerId, CarbonImmutable $from): int;
}
