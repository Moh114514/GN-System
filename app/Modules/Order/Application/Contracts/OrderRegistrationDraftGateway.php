<?php

namespace App\Modules\Order\Application\Contracts;

interface OrderRegistrationDraftGateway
{
    /** @param array<string, mixed> $payload */
    public function save(
        string $draftId,
        int $customerId,
        ?int $appointmentId,
        ?int $institutionId,
        array $payload,
        int $actorId,
    ): void;

    /** @return array{id: string, appointment_id: int|null, institution_id: int|null, payload: array<string, mixed>}|null */
    public function latestOpen(int $customerId): ?array;

    public function complete(string $draftId, int $customerId, int $orderId, ?int $appointmentId): void;
}
