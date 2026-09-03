<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\CustomerOrderGateway;
use App\Modules\Order\Infrastructure\Models\Order;
use DomainException;

final readonly class CustomerOrderRegistrationWorkspace
{
    public function __construct(
        private CustomerOrderReferenceReader $customers,
        private AgentReferenceReader $agents,
        private CustomerOrderGateway $appointments,
        private InstitutionReferenceReader $institutions,
        private AccessContextResolver $access,
    ) {}

    /** @return array<string, mixed> */
    public function context(int $customerId): array
    {
        $customer = $this->customers->customerForOrder($customerId);
        $agent = $this->agents->agentsByIds([(int) $customer['source_agent_id']])[(int) $customer['source_agent_id']] ?? null;
        $appointment = $this->appointments->currentAppointmentForRegistration($customerId);
        $activeInstitutions = array_values($this->institutions->activeInstitutions());
        $activeById = [];
        foreach ($activeInstitutions as $institution) {
            $activeById[(int) $institution['id']] = $institution;
        }
        $appointmentInstitution = $appointment === null
            ? null
            : ($this->institutions->institutionsByIds([$appointment['institution_id']])[$appointment['institution_id']] ?? null);

        return [
            'customer' => $customer,
            'agent' => $agent,
            'appointment' => $appointment,
            'institution' => $appointmentInstitution,
            'institutions' => $activeInstitutions,
            'institution_locked' => $appointmentInstitution !== null
                && isset($activeById[(int) $appointmentInstitution['id']]),
            'can_register' => ($customer['current_status_key'] ?? null) === 'arrived',
            'has_orders' => $this->appointments->hasAnyOrder($customerId),
        ];
    }

    public function assertCanRegister(int $customerId): void
    {
        $customer = $this->customers->customerForOrder($customerId);
        $context = $this->access->current();
        if ($context->isCustomerService() && (int) ($customer['owner_id'] ?? 0) !== (int) ($context->userId ?? 0)) {
            abort(403);
        }
        if (($customer['current_status_key'] ?? null) !== 'arrived') {
            throw new DomainException(__('orders.errors.customer_not_arrived'));
        }
    }

    public function assertActiveInstitution(int $institutionId): void
    {
        if ($institutionId < 1 || ! isset($this->institutions->activeInstitutions()[$institutionId])) {
            throw new DomainException(__('orders.errors.institution_unavailable'));
        }
    }

    /** @return array{id: int, institution: string, project_name: string, amount_krw: int, occurred_on: string|null, status: string} */
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
            'occurred_on' => $order->occurred_on?->format('Y-m-d'),
            'status' => (string) $order->status,
        ];
    }
}
