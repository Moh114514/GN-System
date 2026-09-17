<?php

namespace App\Modules\Settlement\Application\Services;

use App\Support\Exports\DTO\FinancialDocumentData;
use App\Support\Exports\FinancialWorkbookTemplate;
use Carbon\CarbonImmutable;

final readonly class BdQuarterlyCommissionDocumentGenerator
{
    public function __construct(private FinancialWorkbookTemplate $template) {}

    /** @param array{period: object, items: list<array<string, mixed>>, adjustments: list<array<string, mixed>>} $detail */
    public function data(array $detail, int $bdUserId): FinancialDocumentData
    {
        $period = $detail['period'];
        $items = $detail['items'];
        $adjustments = $detail['adjustments'];
        $bdName = (string) ($items[0]['bd_name'] ?? $adjustments[0]['bd_name'] ?? __('settlements.bd_commission.unknown_bd'));
        $groupNames = collect($items)->pluck('business_group_name')->filter()->unique()->values()->all();
        $groupName = $groupNames === [] ? __('settlements.bd_commission.unknown_group') : implode(', ', $groupNames);
        $rows = array_map(static function (array $item): array {
            return [
                'order_id' => '#'.(int) $item['order_id'],
                'occurred_on' => (string) $item['occurred_on'],
                'customer_agent' => (string) ($item['agent_name'] ?? '—'),
                'sales_krw' => (int) $item['basis_krw'],
                'basis_krw' => (int) $item['basis_krw'],
                'rate_bps' => (int) $item['rate_bps'],
                'commission_krw' => (int) $item['commission_krw'],
            ];
        }, $items);
        foreach ($adjustments as $index => $adjustment) {
            $rows[] = [
                'order_id' => 'ADJ-'.($index + 1),
                'occurred_on' => '',
                'customer_agent' => (string) $adjustment['reason'],
                'sales_krw' => 0,
                'basis_krw' => 0,
                'rate_bps' => 0,
                'commission_krw' => (int) $adjustment['amount_krw'],
            ];
        }
        $basis = (int) ($period->total_basis_krw ?? array_sum(array_column($items, 'basis_krw')));
        $baseCommission = array_sum(array_column($items, 'commission_krw'));
        $adjustment = (int) ($period->total_adjustment_krw ?? array_sum(array_column($adjustments, 'amount_krw')));
        $payable = (int) ($period->total_commission_krw ?? ($baseCommission + $adjustment));

        return new FinancialDocumentData(
            title: __('settlements.bd_commission.documents.title'),
            documentNumber: 'BDC-'.(int) $period->id.'-'.$bdUserId,
            documentDate: CarbonImmutable::now('Asia/Shanghai')->toDateString(),
            subject: $bdName,
            period: $period->quarter_start->format('Y-m-d').' — '.$period->quarter_end->format('Y-m-d'),
            primaryAmount: $payable,
            currency: 'KRW',
            metadata: [
                ['label' => __('settlements.bd_commission.documents.bd'), 'value' => $bdName],
                ['label' => __('settlements.bd_commission.documents.business_group'), 'value' => $groupName],
                ['label' => __('settlements.bd_commission.documents.status'), 'value' => __('settlements.bd_commission.statuses.'.$period->status)],
                ['label' => __('settlements.bd_commission.documents.item_count'), 'value' => (string) count($items)],
            ],
            columns: [
                ['key' => 'order_id', 'label' => __('settlements.bd_commission.documents.headers.order'), 'type' => 'text', 'width' => 14],
                ['key' => 'occurred_on', 'label' => __('settlements.bd_commission.documents.headers.occurred_on'), 'type' => 'date', 'width' => 14],
                ['key' => 'customer_agent', 'label' => __('settlements.bd_commission.documents.headers.customer_agent'), 'type' => 'text', 'width' => 28],
                ['key' => 'sales_krw', 'label' => __('settlements.bd_commission.documents.headers.sales'), 'type' => 'amount', 'width' => 16],
                ['key' => 'basis_krw', 'label' => __('settlements.bd_commission.documents.headers.basis'), 'type' => 'amount', 'width' => 16],
                ['key' => 'rate_bps', 'label' => __('settlements.bd_commission.documents.headers.rate'), 'type' => 'percent', 'width' => 12],
                ['key' => 'commission_krw', 'label' => __('settlements.bd_commission.documents.headers.commission'), 'type' => 'amount', 'width' => 16],
            ],
            rows: $rows,
            summaryRows: [
                ['label' => __('settlements.bd_commission.documents.sales_total'), 'value' => $basis, 'type' => 'amount'],
                ['label' => __('settlements.bd_commission.documents.basis_total'), 'value' => $basis, 'type' => 'amount'],
                ['label' => __('settlements.bd_commission.documents.commission_total'), 'value' => $baseCommission, 'type' => 'amount'],
                ['label' => __('settlements.bd_commission.documents.adjustment_total'), 'value' => $adjustment, 'type' => 'amount'],
                ['label' => __('settlements.bd_commission.documents.payable'), 'value' => $payable, 'type' => 'amount', 'emphasis' => true],
            ],
            remarks: [__('settlements.bd_commission.documents.remark', ['status' => __('settlements.bd_commission.statuses.'.$period->status)])],
            primaryAmountLabel: __('settlements.bd_commission.documents.primary_amount'),
            currencyDecimals: 0,
        );
    }

    public function xlsx(FinancialDocumentData $data): void
    {
        $this->template->writeXlsx($data, 'php://output');
    }

    public function pdf(FinancialDocumentData $data): string
    {
        return $this->template->renderPdf($data);
    }
}
