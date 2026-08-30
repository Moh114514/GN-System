<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentBusinessAttributionReader;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OrderAttributionSnapshotBackfillService
{
    public function __construct(
        private AgentBusinessAttributionReader $attributions,
        private AuditRecorder $audit,
    ) {}

    /**
     * @return array{
     *     total: int,
     *     resolved: list<array{order: Order, snapshot: array<string, mixed>}>,
     *     unresolved: list<array{order_id: string, reason: string}>
     * }
     */
    public function preview(): array
    {
        $resolved = [];
        $unresolved = [];
        $orders = Order::query()
            ->where('status', 'completed')
            ->whereNull('business_attribution_snapshot')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            if ($order->occurred_on === null || $order->agent_id === null) {
                $unresolved[] = ['order_id' => (string) $order->id, 'reason' => 'missing occurred_on or agent_id'];

                continue;
            }

            $attribution = $this->attributions->forAgentOnDate(
                (int) $order->agent_id,
                CarbonImmutable::parse($order->occurred_on),
            );
            if ($attribution === null || ! isset($attribution['business_group_id'], $attribution['bd_manager'])) {
                $unresolved[] = ['order_id' => (string) $order->id, 'reason' => 'no unique business-group and BD attribution'];

                continue;
            }

            $resolved[] = [
                'order' => $order,
                'snapshot' => [
                    'source' => 'historical_backfill',
                    'agent_id' => (int) $order->agent_id,
                    'business_group' => $attribution,
                    'occurred_on' => $order->occurred_on->toDateString(),
                ],
            ];
        }

        return [
            'total' => $orders->count(),
            'resolved' => $resolved,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * @param  list<array{order: Order, snapshot: array<string, mixed>}>  $resolved
     */
    public function apply(array $resolved, int $actorId, string $reason): void
    {
        DB::transaction(function () use ($resolved, $actorId, $reason): void {
            foreach ($resolved as $item) {
                $order = $item['order'];
                $updated = Order::query()
                    ->whereKey($order->id)
                    ->whereNull('business_attribution_snapshot')
                    ->update(['business_attribution_snapshot' => $item['snapshot']]);
                if ($updated !== 1) {
                    throw new RuntimeException("Order {$order->id} changed before snapshot backfill.");
                }
                $this->audit->record(
                    description: __('audit.messages.historical_order_attribution_backfilled'),
                    properties: ['reason' => $reason, 'snapshot_source' => 'historical_backfill'],
                    causerId: $actorId,
                    subject: $order,
                    logName: 'order',
                    event: 'attribution_snapshot_backfilled',
                    messageKey: 'audit.messages.historical_order_attribution_backfilled',
                );
            }
        });
    }
}
