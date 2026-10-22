<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\OrderRegistrationDraftGateway;
use App\Modules\Order\Infrastructure\Models\OrderRegistrationDraft;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseOrderRegistrationDraftGateway implements OrderRegistrationDraftGateway
{
    /** @param array<string, mixed> $payload */
    public function save(
        string $draftId,
        int $customerId,
        ?int $appointmentId,
        ?int $institutionId,
        array $payload,
        int $actorId,
    ): void {
        DB::transaction(function () use ($draftId, $customerId, $appointmentId, $institutionId, $payload, $actorId): void {
            $draft = OrderRegistrationDraft::query()->lockForUpdate()->find($draftId);
            if ($draft !== null && ((int) $draft->customer_id !== $customerId || $draft->completed_at !== null)) {
                abort(409);
            }

            OrderRegistrationDraft::query()->updateOrCreate(
                ['id' => $draftId],
                [
                    'customer_id' => $customerId,
                    'appointment_id' => $appointmentId,
                    'institution_id' => $institutionId,
                    'created_by' => $draft->created_by ?? $actorId,
                    'payload' => $payload,
                    'completed_order_id' => null,
                    'completed_at' => null,
                ],
            );
        }, 3);
    }

    public function latestOpen(int $customerId): ?array
    {
        $draft = OrderRegistrationDraft::query()
            ->where('customer_id', $customerId)
            ->whereNull('completed_at')
            ->latest('updated_at')
            ->first();

        return $draft === null ? null : [
            'id' => (string) $draft->id,
            'appointment_id' => $draft->appointment_id === null ? null : (int) $draft->appointment_id,
            'institution_id' => $draft->institution_id === null ? null : (int) $draft->institution_id,
            'payload' => $draft->payload,
        ];
    }

    public function complete(string $draftId, int $customerId, int $orderId, ?int $appointmentId): void
    {
        OrderRegistrationDraft::query()
            ->whereKey($draftId)
            ->where('customer_id', $customerId)
            ->whereNull('completed_at')
            ->update([
                'appointment_id' => $appointmentId,
                'completed_order_id' => $orderId,
                'completed_at' => now(),
            ]);
    }
}
