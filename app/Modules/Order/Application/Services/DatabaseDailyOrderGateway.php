<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Agent\Application\Contracts\AgentReferenceReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Application\Contracts\DailyOrderGateway;
use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Data\OrderSummaryData;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Application\Contracts\TreatmentReminderGateway;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Settlement\Application\Contracts\DailyCommissionGateway;
use App\Modules\Settlement\Application\Data\CompletedOrderCommissionData;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DatabaseDailyOrderGateway implements DailyOrderGateway
{
    public function __construct(
        private AgentReferenceReader $agents,
        private DailyCommissionGateway $commissions,
        private TreatmentReminderGateway $reminders,
        private AuditRecorder $audit,
        private AccessContextResolver $access,
        private CustomerOrderReferenceReader $customers,
        private AgentBusinessAttributionReader $attributions,
    ) {}

    public function create(DailyOrderData $data): int
    {
        $this->assertOrderAgentAccess($data->agentId);
        $this->assertAgent($data->agentId);

        return DB::transaction(function () use ($data): int {
            if (! in_array($data->status, ['pending', 'completed'], true)) {
                throw new DomainException(__('orders.errors.invalid_status'));
            }
            $completedAt = $data->status === 'completed' && $data->completedOn !== null
                ? $data->completedOn->setTimezone('Asia/Shanghai')
                : null;
            $order = Order::query()->create([
                'customer_id' => $data->customerId,
                'institution_id' => $data->institutionId,
                'agent_id' => $data->agentId,
                'project_name' => trim($data->projectName),
                'amount_krw' => $data->amountKrw,
                'completed_on' => $data->status === 'completed' ? $data->completedOn : null,
                'occurred_on' => $data->status === 'completed' ? $data->completedOn : null,
                'completed_at' => $completedAt,
                'completion_precision' => $completedAt === null ? 'date' : 'datetime',
                'treatment_project_snapshot' => trim($data->projectName),
                'treatment_project_id' => $data->treatmentProjectId,
                'translator_language_id' => $data->translatorLanguageId,
                'translator_language_snapshot' => $data->translatorLanguageName,
                'translator_name' => $data->translatorName,
                'owner_id' => $data->ownerId,
                'status' => $data->status,
                'notes' => $data->notes,
                'business_attribution_snapshot' => $data->status === 'completed' && $data->completedOn !== null
                    ? [
                        'source' => 'daily_order',
                        'agent' => $this->agents->agentById($data->agentId),
                        'business_group' => $this->attributions->forAgentOnDate($data->agentId, $data->completedOn),
                        'occurred_on' => $data->completedOn->toDateString(),
                    ]
                    : null,
            ]);

            if ($data->status === 'completed') {
                if ($data->completedOn === null) {
                    throw new DomainException(__('orders.errors.completed_date_required'));
                }
                $this->recordCommission($order, $data->completedOn, $data->ownerId, $data->ipAddress);
                $this->scheduleReminders($order, $data->completedOn, $data->ownerId);
            }

            $this->audit->record(
                description: __('orders.audit.created'),
                properties: ['after' => $order->only(['customer_id', 'institution_id', 'agent_id', 'project_name', 'amount_krw', 'status', 'completed_on', 'completed_at', 'completion_precision'])],
                causerId: $data->ownerId,
                subject: $order,
                logName: 'order',
                event: 'created',
                ipAddress: $data->ipAddress,
            );

            return (int) $order->id;
        });
    }

    public function complete(int $orderId, CarbonImmutable $completedOn, int $actorId, ?string $ipAddress): int
    {
        return DB::transaction(function () use ($orderId, $completedOn, $actorId, $ipAddress): int {
            $order = Order::query()->lockForUpdate()->findOrFail($orderId);
            $this->assertOrderVisible($order);
            if ($order->status === 'completed') {
                return (int) $order->id;
            }
            $this->assertAgent($order->agent_id === null ? 0 : (int) $order->agent_id);
            $before = $order->only(['status', 'completed_on', 'completed_at', 'completion_precision']);
            $order->update([
                'status' => 'completed',
                'completed_on' => $completedOn,
                'occurred_on' => $completedOn,
                'completed_at' => $completedOn->setTimezone('Asia/Shanghai'),
                'completion_precision' => 'datetime',
                'treatment_project_snapshot' => $order->treatment_project_snapshot ?: $order->project_name,
                'business_attribution_snapshot' => [
                    ...((array) $order->business_attribution_snapshot),
                    'source' => 'daily_order',
                    'agent' => $this->agents->agentById((int) $order->agent_id),
                    'business_group' => $this->attributions->forAgentOnDate((int) $order->agent_id, $completedOn),
                    'occurred_on' => $completedOn->toDateString(),
                ],
            ]);
            $this->recordCommission($order, $completedOn, $actorId, $ipAddress);
            $this->scheduleReminders($order, $completedOn, $actorId);
            $this->audit->record(
                description: __('orders.audit.completed'),
                properties: ['before' => $before, 'after' => $order->only(['status', 'completed_on', 'completed_at', 'completion_precision'])],
                causerId: $actorId,
                subject: $order,
                logName: 'order',
                event: 'completed',
                ipAddress: $ipAddress,
            );

            return (int) $order->id;
        });
    }

    public function forCustomer(int $customerId): array
    {
        $this->customers->customerForOrder($customerId);

        return $this->summaries(Order::query()->where('customer_id', $customerId)->latest('id')->get());
    }

    public function forAgent(int $agentId): array
    {
        abort_unless($this->access->current()->canViewAgent($agentId), 404);

        return $this->summaries(Order::query()->where('agent_id', $agentId)->latest('id')->limit(100)->get());
    }

    private function assertAgent(int $agentId): void
    {
        if ($agentId < 1) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
        $agent = $this->agents->agentById($agentId);
        if ($agent['cooperation_status'] !== 'active') {
            throw new DomainException(__('orders.errors.agent_inactive_save'));
        }
    }

    private function assertOrderAgentAccess(int $agentId): void
    {
        $context = $this->access->current();
        if (! $context->isSuperAdmin() && ! $context->canViewAgent($agentId)) {
            throw new DomainException(__('orders.errors.agent_required'));
        }
    }

    private function assertOrderVisible(Order $order): void
    {
        $context = $this->access->current();
        abort_unless($context->canViewOrder(
            $order->agent_id === null ? null : (int) $order->agent_id,
            $order->owner_id === null ? null : (int) $order->owner_id,
        ), 404);
    }

    private function recordCommission(Order $order, CarbonImmutable $completedOn, int $actorId, ?string $ipAddress): void
    {
        $this->commissions->recordForCompletedOrder(new CompletedOrderCommissionData(
            orderId: (int) $order->id,
            agentId: (int) $order->agent_id,
            institutionId: (int) $order->institution_id,
            orderAmountKrw: (int) $order->amount_krw,
            completedOn: $completedOn,
            actorId: $actorId,
            ipAddress: $ipAddress,
        ));
    }

    private function scheduleReminders(Order $order, CarbonImmutable $completedOn, int $actorId): void
    {
        $this->reminders->schedule(new CompletedTreatmentData(
            orderId: (int) $order->id,
            customerId: (int) $order->customer_id,
            projectName: (string) $order->project_name,
            completedOn: $completedOn,
            ownerId: $order->owner_id === null ? null : (int) $order->owner_id,
            actorId: $actorId,
        ));
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return array<int, OrderSummaryData>
     */
    private function summaries(Collection $orders): array
    {
        $commissions = DB::table('order_commissions')
            ->whereIn('order_id', $orders->modelKeys())
            ->get()
            ->keyBy('order_id');

        return $orders->map(function (Order $order) use ($commissions): OrderSummaryData {
            $commission = $commissions->get($order->id);

            return new OrderSummaryData(
                id: (int) $order->id,
                customerId: (int) $order->customer_id,
                institutionId: (int) $order->institution_id,
                agentId: (int) $order->agent_id,
                projectName: (string) $order->project_name,
                amountKrw: (int) $order->amount_krw,
                status: (string) $order->status,
                occurredOn: $order->occurred_on?->format('Y-m-d'),
                completedOn: $order->completed_on?->format('Y-m-d'),
                commissionAmountKrw: $commission === null ? null : (int) $commission->amount_krw,
                commissionRateBps: $commission === null ? null : (int) $commission->rate_bps,
            );
        })->all();
    }
}
