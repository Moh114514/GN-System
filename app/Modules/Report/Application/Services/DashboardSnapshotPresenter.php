<?php

namespace App\Modules\Report\Application\Services;

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

        return $snapshot;
    }

    private function label(string $value): string
    {
        return match ($value) {
            '__dashboard_missing_agent__' => __('dashboard.fallbacks.missing_agent'),
            '__dashboard_missing_customer__' => __('dashboard.fallbacks.missing_customer'),
            '__dashboard_missing_institution__' => __('dashboard.fallbacks.missing_institution'),
            '__dashboard_unassigned__' => __('dashboard.fallbacks.unassigned'),
            '__dashboard_repurchase_rate__' => __('dashboard.export.chart_labels.repurchase_rate'),
            '__dashboard_followup_completion_rate__' => __('dashboard.export.chart_labels.followup_completion_rate'),
            default => $value,
        };
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
