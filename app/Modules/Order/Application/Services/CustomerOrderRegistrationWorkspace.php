<?php

namespace App\Modules\Order\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use Carbon\CarbonImmutable;
use DomainException;

final readonly class CustomerOrderRegistrationWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private CustomerOrderGateway $appointments,
        private InstitutionReferenceReader $institutions,
        private AccessContextResolver $access,
        private DailyCommissionGateway $commissions,
        private DirectCommissionConfigurationGateway $directCommissionRates,
        private BusinessClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function context(int $customerId): array
    {
        $customer = $this->customers->customerForOrder($customerId);
        $agent = $customer['source_agent_id'] === null
            ? null
            : ($this->agents->agentsByIds([(int) $customer['source_agent_id']])[(int) $customer['source_agent_id']] ?? null);
        $appointment = $this->appointments->currentAppointmentForRegistration($customerId);
        $activeInstitutions = array_values($this->institutions->activeInstitutions());
        $appointmentInstitution = $appointment === null
            ? null
            : ($this->institutions->institutionsByIds([$appointment['institution_id']])[$appointment['institution_id']] ?? null);

        return [
            'customer' => $customer,
            'agent' => $agent,
            'appointment' => $appointment,
            'institution' => $appointmentInstitution,
            'institutions' => $activeInstitutions,
            'institution_locked' => $appointmentInstitution !== null,
            'can_register' => ($customer['current_status_key'] ?? null) === 'arrived'
                && ($appointment['status'] ?? null) === 'arrived'
                && ($appointment['arrived_at'] ?? null) !== null
                || ($customer['current_status_key'] ?? null) === 'treatment_completed'
                && ($appointment['status'] ?? null) === 'completed'
                && ($appointment['arrived_at'] ?? null) !== null
                && $appointment['has_order'],
            'visit_arrived_at' => $appointment['arrived_at'] ?? null,
            'has_orders' => $this->appointments->hasAnyOrder($customerId),
        ];
    }

    /** @return array{source_type: string, agent_name: string|null, grade_name: string|null, rate_bps: int, effective_month: string} */
    public function commissionPreview(int $customerId, int $institutionId): array
    {
        $customer = $this->customers->customerForOrder($customerId);
        $appointment = $this->appointments->currentAppointmentForRegistration($customerId);
        $arrivedAt = $appointment['arrived_at'] ?? null;
        if ($arrivedAt === null) {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }
        if ($appointment === null || (int) $appointment['institution_id'] !== $institutionId) {
            throw new DomainException(__('orders.errors.visit_institution_mismatch'));
        }
        $occurredOn = CarbonImmutable::parse($arrivedAt)->startOfDay();
        if ($customer['source_type'] === 'direct') {
            $registrationAt = $this->clock->now();
            $rate = $this->directCommissionRates->rateForDate($registrationAt);
            if ($rate === null) {
                throw new DomainException(__('settlements.direct_commission.errors.rate_missing'));
            }

            return [
                'source_type' => 'direct',
                'agent_name' => null,
                'grade_name' => null,
                'rate_bps' => (int) $rate['rate_bps'],
                'effective_month' => (string) $rate['effective_from'],
            ];
        }
        $agentId = $customer['source_agent_id'] ?? null;
        if ($agentId === null) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
        $preview = $this->commissions->previewForOrder((int) $agentId, $institutionId, $occurredOn);

        return [
            'source_type' => 'agent',
            'agent_name' => $preview['agent_name'],
            'grade_name' => $preview['grade_name'],
            'rate_bps' => $preview['rate_bps'],
            'effective_month' => $preview['effective_month'],
        ];
    }

    public function assertCanRegister(int $customerId): void
    {
        $customer = $this->customers->customerForOrder($customerId);
        $context = $this->access->current();
        if ($context->isCustomerService() && (int) ($customer['owner_id'] ?? 0) !== (int) ($context->userId ?? 0)) {
            abort(403);
        }
        $visit = $this->appointments->currentAppointmentForRegistration($customerId);
        $canRegister = ($customer['current_status_key'] ?? null) === 'arrived'
            && ($visit['status'] ?? null) === 'arrived'
            && ($visit['arrived_at'] ?? null) !== null
            || ($customer['current_status_key'] ?? null) === 'treatment_completed'
            && ($visit['status'] ?? null) === 'completed'
            && ($visit['arrived_at'] ?? null) !== null
            && $visit['has_order'];
        if (! $canRegister) {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }
    }

    public function assertCanSaveDraft(int $customerId): void
    {
        $customer = $this->customers->customerForOrder($customerId);
        $context = $this->access->current();
        if ($context->isCustomerService() && (int) ($customer['owner_id'] ?? 0) !== (int) ($context->userId ?? 0)) {
            abort(403);
        }
    }

    public function assertActiveInstitution(int $institutionId): void
    {
        if ($institutionId < 1 || ! isset($this->institutions->activeInstitutions()[$institutionId])) {
            throw new DomainException(__('orders.errors.institution_unavailable'));
        }
    }

    public function assertVisitInstitution(int $customerId, int $institutionId): void
    {
        $appointment = $this->appointments->currentAppointmentForRegistration($customerId);
        if ($appointment === null || (int) $appointment['institution_id'] !== $institutionId) {
            throw new DomainException(__('orders.errors.visit_institution_mismatch'));
        }
    }

    /** @return array{id: int, institution: string, project_name: string, amount_krw: int, item_count: int, occurred_on: string|null, status: string} */
    public function result(int $customerId, int $orderId): array
    {
        $this->customers->customerForOrder($customerId);
        $order = Order::query()->whereKey($orderId)->where('customer_id', $customerId)->firstOrFail();
        $institution = $this->institutions->institutionsByIds([(int) $order->institution_id])[(int) $order->institution_id] ?? null;

        return [
            'id' => (int) $order->id,
            'institution' => (string) ($institution['name'] ?? __('orders.values.unknown_institution')),
            'project_name' => (string) $order->project_name,
            'amount_krw' => (int) $order->amount_krw,
            'item_count' => (int) $order->items()->count(),
            'occurred_on' => $order->occurred_on?->format('Y-m-d'),
            'status' => (string) $order->status,
        ];
    }
}
