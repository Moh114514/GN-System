<?php

namespace App\Modules\Customer\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Agent\Application\Contracts\ReportAgentReader;
use App\Modules\Auth\Application\Contracts\ReportUserReader;
use App\Modules\Customer\Application\Contracts\ReportCustomerReader;
use App\Modules\Reminder\Application\Contracts\ReportReminderReader;
use Illuminate\Support\Facades\Lang;

final readonly class CustomerOverviewService
{
    public function __construct(
        private ReportCustomerReader $customers,
        private ReportReminderReader $reminders,
        private ReportAgentReader $agents,
        private ReportUserReader $users,
        private BusinessClock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $now = $this->clock->now();
        $customer = $this->customers->overview($now);
        $reminder = $this->reminders->overview($now);
        $recentCustomers = $customer['recent_customers'];
        $customerNames = $this->customers->namesByIds(array_column($reminder['today_tasks'], 'customer_id'));
        $agentNames = $this->agents->namesByIds(array_column($recentCustomers, 'source_id'));
        $ownerNames = $this->users->namesByIds(array_column($recentCustomers, 'owner_id'));

        return [
            'pending_reminders' => $reminder['pending_reminders'],
            'today_tasks' => array_map(fn (array $task): array => [
                ...$task,
                'customer_name' => $customerNames[$task['customer_id']] ?? __('customers.overview.fallbacks.missing_customer'),
                'title' => $this->taskTitle($task),
                'tag' => $this->reminderTag($task['tag']),
            ], $reminder['today_tasks']),
            'lifecycle' => $this->lifecycle($customer),
            'recent_customers' => array_map(fn (array $recent): array => [
                ...$recent,
                'source_name' => $agentNames[$recent['source_id']] ?? __('customers.overview.fallbacks.missing_agent'),
                'owner_name' => $ownerNames[$recent['owner_id']] ?? __('customers.overview.fallbacks.unassigned'),
                'status_name' => $this->statusName($recent),
            ], $recentCustomers),
            'generated_at' => $now->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     * @return array<int, array{key: string, value: int, percentage: float}>
     */
    private function lifecycle(array $customer): array
    {
        $total = (int) $customer['total_customers'];
        $rows = [
            'booked' => (int) ($customer['status_counts']['booked'] ?? 0),
            'arrived' => (int) ($customer['status_counts']['arrived'] ?? 0),
            'treatment_completed' => (int) ($customer['status_counts']['treatment_completed'] ?? 0),
        ];

        return array_map(fn (string $key, int $value): array => [
            'key' => $key,
            'value' => $value,
            'percentage' => $total === 0 ? 0.0 : round($value / $total * 100, 1),
        ], array_keys($rows), array_values($rows));
    }

    /** @param array<string, mixed> $task */
    private function taskTitle(array $task): string
    {
        $key = $task['title_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return __($key, is_array($task['title_parameters'] ?? null) ? $task['title_parameters'] : []);
        }

        return (string) $task['title'];
    }

    private function reminderTag(string $token): string
    {
        if (str_contains($token, '.')) {
            return __($token);
        }

        $key = 'reminders.report_types.'.$token;

        return Lang::has($key) ? __($key) : __('reminders.report_types.default');
    }

    /** @param array<string, mixed> $customer */
    private function statusName(array $customer): string
    {
        $translationKey = $customer['status_translation_key'] ?? null;
        if (is_string($translationKey) && Lang::has($translationKey)) {
            return __($translationKey);
        }

        return (string) ($customer['status_name'] ?? '');
    }
}
