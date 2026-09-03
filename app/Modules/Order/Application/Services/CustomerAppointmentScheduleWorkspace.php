<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Reminder\Application\Contracts\AppointmentReminderGateway;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CustomerAppointmentScheduleWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private CustomerOrderGateway $appointments,
        private InstitutionReferenceReader $institutions,
        private AppointmentReminderGateway $reminders,
        private AccessContextResolver $access,
        private AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function context(int $customerId): array
    {
        $customer = $this->customers->customerForOrder($customerId);
        $appointment = $this->appointments->latestAppointmentForCustomer($customerId);
        $institution = $appointment === null
            ? null
            : ($this->institutions->institutionsByIds([$appointment['institution_id']])[$appointment['institution_id']] ?? null);

        return [
            'customer' => $customer,
            'appointment' => $appointment,
            'institution' => $institution,
            'can_edit' => $appointment !== null
                && $this->canEdit((int) ($customer['owner_id'] ?? 0)),
        ];
    }

    public function reschedule(int $customerId, int $appointmentId, CarbonImmutable $scheduledAt, int $actorId, ?string $ipAddress): void
    {
        $customer = $this->customers->customerForOrder($customerId);
        if (! $this->canEdit((int) ($customer['owner_id'] ?? 0))) {
            abort(403);
        }

        DB::transaction(function () use ($customerId, $appointmentId, $scheduledAt, $actorId, $ipAddress): void {
            $changed = $this->appointments->rescheduleAppointment($customerId, $appointmentId, $scheduledAt);
            if ($changed === null) {
                throw new DomainException(__('orders.errors.appointment_not_editable'));
            }
            if ($changed['current']['status'] === 'scheduled') {
                $this->reminders->syncForAppointment(
                    appointmentId: $appointmentId,
                    customerId: $customerId,
                    assignedTo: $changed['current']['owner_id'],
                    scheduledAt: $scheduledAt,
                );
            } else {
                $this->reminders->cancelForAppointment($appointmentId, $actorId, 'appointment_not_pending');
            }
            $this->audit->record(
                description: '更新客户预计到院时间',
                properties: [
                    'customer_id' => $customerId,
                    'appointment_id' => $appointmentId,
                    'before' => $changed['previous']['scheduled_at'],
                    'after' => $changed['current']['scheduled_at'],
                ],
                causerId: $actorId,
                logName: 'order',
                event: 'appointment_rescheduled',
                ipAddress: $ipAddress,
            );
        }, 3);
    }

    private function canEdit(int $ownerId): bool
    {
        $context = $this->access->current();

        return $context->isSuperAdmin()
            || $context->isBdManager()
            || ($context->isCustomerService() && $ownerId === (int) ($context->userId ?? 0));
    }
}
