<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Data\OrderImportData;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderImportGateway implements OrderImportGateway
{
    public function upsertOrder(OrderImportData $data): int
    {
        $appointmentId = null;
        if ($data->scheduledAt !== null) {
            $appointment = Appointment::query()->firstOrCreate(
                [
                    'customer_id' => $data->customerId,
                    'institution_id' => $data->institutionId,
                    'scheduled_at' => $data->scheduledAt,
                ],
                [
                    'treatment_project_snapshot' => $data->projectName,
                    'translator_name' => $data->translatorName,
                    'status' => 'completed',
                    'notes' => $data->notes,
                    'import_batch_id' => $data->importBatchId,
                ],
            );
            $appointmentId = $appointment->id;
        }

        $order = Order::query()->updateOrCreate(
            [
                'customer_id' => $data->customerId,
                'institution_id' => $data->institutionId,
                'completed_on' => $data->completedOn,
                'amount_krw' => $data->amountKrw,
            ],
            [
                'appointment_id' => $appointmentId,
                'channel' => $data->channel,
                'agent_id' => $data->agentId,
                'direct_sales_source_id' => $data->directSalesSourceId,
                'project_name' => $data->projectName,
                'treatment_project_snapshot' => $data->projectName,
                'translator_name' => $data->translatorName,
                'status' => $data->completedOn === null ? 'pending' : 'completed',
                'completed_at' => $data->completedOn === null
                    ? null
                    : CarbonImmutable::parse($data->completedOn->toDateString().' 00:00:00', 'Asia/Shanghai'),
                'completion_precision' => 'date',
                'notes' => $data->notes,
                'import_batch_id' => $data->importBatchId,
            ],
        );

        return $order->id;
    }

    public function deleteImportedByBatch(string $batchId): int
    {
        $deleted = Order::withTrashed()->where('import_batch_id', $batchId)->forceDelete();
        Appointment::query()->where('import_batch_id', $batchId)->delete();

        return $deleted;
    }

    public function rollbackBlockers(string $batchId, DateTimeInterface $completedAt): array
    {
        $blockers = [];
        foreach (['orders', 'appointments'] as $table) {
            $ids = DB::table($table)
                ->where('import_batch_id', $batchId)
                ->where('updated_at', '>', $completedAt)
                ->pluck('id');

            foreach ($ids as $id) {
                $blockers[] = "{$table}:{$id}";
            }
        }

        return $blockers;
    }
}
