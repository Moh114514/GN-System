<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\CustomerAppointmentScheduleWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

class CustomerAppointmentSchedule extends Component
{
    public int $customerId;

    public string $scheduledAt = '';

    public string $status = '';

    public bool $canEdit = false;

    public function mount(int $customerId, CustomerAppointmentScheduleWorkspace $workspace): void
    {
        $this->customerId = $customerId;
        $this->loadContext($workspace->context($customerId));
    }

    #[On('customer-status-updated')]
    public function refreshAfterStatusChange(int $customerId, CustomerAppointmentScheduleWorkspace $workspace): void
    {
        if ($customerId === $this->customerId) {
            $this->loadContext($workspace->context($customerId));
        }
    }

    public function save(CustomerAppointmentScheduleWorkspace $workspace): void
    {
        $this->validate(['scheduledAt' => ['required', 'date_format:Y-m-d\\TH:i']]);
        $actorId = Auth::id();
        abort_unless(is_int($actorId), 403);

        try {
            $workspace->reschedule(
                customerId: $this->customerId,
                appointmentId: (int) $this->appointmentId($workspace),
                scheduledAt: CarbonImmutable::parse($this->scheduledAt, (string) config('app.timezone')),
                actorId: $actorId,
                ipAddress: request()->ip(),
            );
        } catch (DomainException $exception) {
            $this->addError('scheduledAt', $exception->getMessage());

            return;
        }

        $this->loadContext($workspace->context($this->customerId));
        Flux::toast(variant: 'success', text: __('orders.appointment_schedule.saved'));
    }

    public function render(CustomerAppointmentScheduleWorkspace $workspace): View
    {
        return view('livewire.orders.customer-appointment-schedule', [
            'context' => $workspace->context($this->customerId),
            'modalName' => 'customer-appointment-schedule-'.$this->customerId,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function loadContext(array $context): void
    {
        $appointment = $context['appointment'] ?? null;
        $this->scheduledAt = $appointment !== null && $appointment['scheduled_at'] !== null
            ? CarbonImmutable::parse($appointment['scheduled_at'])->format('Y-m-d\\TH:i')
            : '';
        $this->status = (string) ($appointment['status'] ?? '');
        $this->canEdit = (bool) ($context['can_edit'] ?? false);
    }

    private function appointmentId(CustomerAppointmentScheduleWorkspace $workspace): int
    {
        $appointment = $workspace->context($this->customerId)['appointment'] ?? null;
        abort_unless(is_array($appointment) && isset($appointment['id']), 422, __('orders.errors.appointment_not_found'));

        return (int) $appointment['id'];
    }
}
