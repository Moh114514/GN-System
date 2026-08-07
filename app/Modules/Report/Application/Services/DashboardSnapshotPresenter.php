<?php

namespace App\Modules\Report\Application\Services;

use Illuminate\Support\Facades\Lang;

final class DashboardSnapshotPresenter
{
    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public function present(array $snapshot): array
    {
        $range = $snapshot['range'] ?? [];
        $rangeLabel = is_array($range) && isset($range['label']) ? (string) $range['label'] : null;
        if ($rangeLabel !== null && in_array($rangeLabel, ['today', 'week', 'month', 'quarter', 'year', 'custom'], true)) {
            $snapshot['range']['label'] = $rangeLabel === 'custom'
                ? $this->customRangeLabel($range)
                : __('dashboard.ranges.'.$rangeLabel);
        }

        foreach (['agent_promotion_ranking', 'source_distribution', 'repurchase_rate', 'followup_completion_rate', 'institution_revenue'] as $chart) {
            foreach (($snapshot['charts'][$chart] ?? []) as $index => $row) {
                if (is_array($row) && isset($row['key'])) {
                    $snapshot['charts'][$chart][$index]['key'] = $this->label((string) $row['key']);
                }
            }
        }
        foreach (($snapshot['panels']['today_tasks'] ?? []) as $index => $task) {
            if (is_array($task) && isset($task['customer_name'])) {
                $snapshot['panels']['today_tasks'][$index]['customer_name'] = $this->label((string) $task['customer_name']);
            }
            if (! is_array($task)) {
                continue;
            }
            if (is_string($task['title_key'] ?? null)) {
                $snapshot['panels']['today_tasks'][$index]['title'] = __(
                    $task['title_key'],
                    is_array($task['title_parameters'] ?? null) ? $task['title_parameters'] : [],
                );
            }
            if (is_string($task['tag'] ?? null)) {
                $snapshot['panels']['today_tasks'][$index]['tag'] = $this->reminderTag($task['tag']);
            }
        }
        foreach (($snapshot['panels']['recent_customers'] ?? []) as $index => $customer) {
            if (! is_array($customer)) {
                continue;
            }
            foreach (['source_name', 'owner_name'] as $field) {
                if (isset($customer[$field])) {
                    $snapshot['panels']['recent_customers'][$index][$field] = $this->label((string) $customer[$field]);
                }
            }
            if (is_string($customer['status_translation_key'] ?? null)
                && Lang::has($customer['status_translation_key'])) {
                $snapshot['panels']['recent_customers'][$index]['status_name'] = __($customer['status_translation_key']);
            }
        }

        return $snapshot;
    }

    private function label(string $value): string
    {
        return match ($value) {
            '__dashboard_missing_agent__' => __('dashboard.fallbacks.missing_agent'),
            '__dashboard_missing_customer__' => __('dashboard.fallbacks.missing_customer'),
            '__dashboard_missing_institution__' => __('dashboard.fallbacks.missing_institution'),
            '__dashboard_missing_direct_source__' => __('dashboard.fallbacks.missing_direct_source'),
            '__dashboard_unassigned__' => __('dashboard.fallbacks.unassigned'),
            '__dashboard_repurchase_rate__' => __('dashboard.export.chart_labels.repurchase_rate'),
            '__dashboard_followup_completion_rate__' => __('dashboard.export.chart_labels.followup_completion_rate'),
            default => $value,
        };
    }

    private function reminderTag(string $token): string
    {
        if (str_contains($token, '.')) {
            return __($token);
        }

        $key = 'reminders.report_types.'.$token;

        return Lang::has($key) ? __($key) : __('reminders.report_types.default');
    }

    /** @param array<string, mixed> $range */
    private function customRangeLabel(array $range): string
    {
        $from = isset($range['from']) ? date('Y-m-d', strtotime((string) $range['from'])) : '';
        $to = isset($range['to']) ? date('Y-m-d', strtotime((string) $range['to'])) : '';

        return $from !== '' && $to !== ''
            ? $from.' '.__('dashboard.ranges.to').' '.$to
            : __('dashboard.ranges.custom');
    }
}
