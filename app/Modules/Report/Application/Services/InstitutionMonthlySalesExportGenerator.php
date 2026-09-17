<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Application\Data\InstitutionMonthlySalesOrderData;
use App\Modules\Report\Application\Data\InstitutionMonthlySalesSummaryData;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use App\Support\Exports\DTO\FinancialDocumentData;
use App\Support\Exports\DTO\FinancialDocumentSectionData;
use App\Support\Exports\FinancialWorkbookTemplate;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final readonly class InstitutionMonthlySalesExportGenerator
{
    public function __construct(private FinancialWorkbookTemplate $template) {}

    /**
     * @param  list<InstitutionMonthlySalesOrderData>  $detailOrders
     * @param  array<int, string>  $customerNames
     */
    public function generate(
        ReportExport $export,
        InstitutionMonthlySalesSummaryData $summary,
        array $detailOrders = [],
        array $customerNames = [],
    ): ReportExport {
        if (! in_array($export->format, ['xlsx', 'pdf'], true)) {
            throw new RuntimeException(__('institution_sales.errors.export_format'));
        }
        $export->update([
            'status' => 'generating',
            'failure_reason' => null,
            'failure_reason_key' => null,
            'failure_reason_parameters' => null,
        ]);
        $absolutePath = null;
        try {
            $disk = Storage::disk('local');
            $path = "reports/exports/{$export->created_by}/{$export->id}.{$export->format}";
            $absolutePath = $disk->path($path);
            $disk->makeDirectory(dirname($path));
            $directory = dirname($absolutePath);
            if (! is_dir($directory) || ! is_writable($directory)) {
                throw new RuntimeException(__('institution_sales.errors.directory_unwritable'));
            }
            if ($export->format === 'pdf') {
                $disk->put($path, $this->template->renderPdf($this->document($summary, $export, $detailOrders, $customerNames)));
            } else {
                $this->writeXlsx($summary, $absolutePath);
            }
            if (! is_file($absolutePath)) {
                throw new RuntimeException(__('institution_sales.errors.file_missing'));
            }
            $sha256 = hash_file('sha256', $absolutePath);
            if ($sha256 === false) {
                throw new RuntimeException(__('institution_sales.errors.checksum_failed'));
            }

            $export->update([
                'status' => 'completed',
                'path' => $path,
                'sha256' => $sha256,
                'generated_at' => now(),
            ]);

            return $export->refresh();
        } catch (Throwable $exception) {
            if (is_string($absolutePath) && is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $exception;
        }
    }

    private function writeXlsx(InstitutionMonthlySalesSummaryData $summary, string $absolutePath): void
    {
        $spreadsheet = new Spreadsheet;
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(__('institution_sales.export.sheet_title'));
            $lastRow = 4 + count($summary->rows);
            $sheet->mergeCells('A1:E1');
            $sheet->setCellValue('A1', __('institution_sales.title'));
            $sheet->mergeCells('A2:E2');
            $sheet->setCellValue('A2', __('institution_sales.export.period', ['month' => $summary->month]));
            $sheet->fromArray([[
                __('institution_sales.table.number'),
                __('institution_sales.table.institution'),
                __('institution_sales.table.customers'),
                __('institution_sales.table.orders'),
                __('institution_sales.table.amount'),
            ]], null, 'A4');

            foreach ($summary->rows as $index => $row) {
                $excelRow = $index + 5;
                $sheet->setCellValue("A{$excelRow}", $index + 1);
                $sheet->setCellValue("B{$excelRow}", $row->institutionName);
                $sheet->setCellValue("C{$excelRow}", $row->customerCount);
                $sheet->setCellValue("D{$excelRow}", $row->orderCount);
                $sheet->setCellValue("E{$excelRow}", $row->amountKrw);
            }

            $totalRow = $lastRow + 1;
            $sheet->setCellValue("B{$totalRow}", __('institution_sales.table.total'));
            $sheet->setCellValue("C{$totalRow}", $summary->totalCustomers);
            $sheet->setCellValue("D{$totalRow}", $summary->totalOrders);
            $sheet->setCellValue("E{$totalRow}", $summary->totalAmountKrw);
            $sheet->getStyle('A1:E1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 16],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle('A4:E4')->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => '0F766E']],
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->getStyle("A{$totalRow}:E{$totalRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => 'CCFBF1']],
                'font' => ['bold' => true],
            ]);
            $sheet->getStyle("A4:E{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("C5:E{$totalRow}")->getNumberFormat()->setFormatCode('#,##0');
            foreach (['A' => 10, 'B' => 32, 'C' => 16, 'D' => 16, 'E' => 20] as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }
            $sheet->freezePane('A5');
            $sheet->setShowGridlines(false);
            $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
            $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
            $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4, 4);
            $sheet->getPageMargins()->setTop(0.35)->setRight(0.3)->setBottom(0.35)->setLeft(0.3);
            $sheet->getPageSetup()->setPrintArea("A1:E{$totalRow}");
            (new Xlsx($spreadsheet))->save($absolutePath);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  list<InstitutionMonthlySalesOrderData>  $detailOrders
     * @param  array<int, string>  $customerNames
     */
    private function document(
        InstitutionMonthlySalesSummaryData $summary,
        ReportExport $export,
        array $detailOrders,
        array $customerNames,
    ): FinancialDocumentData {
        $institutionName = trim((string) ($export->criteria_snapshot['institution_name'] ?? ''));
        $ordersByInstitution = [];
        foreach ($detailOrders as $order) {
            $ordersByInstitution[$order->institutionId][] = $order;
        }
        $columns = [
            ['key' => 'occurred_on', 'label' => __('institution_sales.export.date'), 'type' => 'text', 'width' => 14],
            ['key' => 'order', 'label' => __('institution_sales.export.order'), 'type' => 'text', 'width' => 14],
            ['key' => 'customer', 'label' => __('institution_sales.export.customer'), 'type' => 'text', 'width' => 22],
            ['key' => 'project', 'label' => __('institution_sales.export.project'), 'type' => 'text', 'width' => 24],
            ['key' => 'quantity', 'label' => __('institution_sales.export.quantity'), 'type' => 'number', 'width' => 12],
            ['key' => 'amount', 'label' => __('institution_sales.export.amount'), 'type' => 'amount', 'width' => 18],
            ['key' => 'notes', 'label' => __('institution_sales.export.notes'), 'type' => 'text', 'width' => 26],
        ];
        $sections = [];
        foreach ($summary->rows as $row) {
            $orders = $ordersByInstitution[$row->institutionId] ?? [];
            $rows = [];
            $customerIds = [];
            foreach ($orders as $order) {
                $customerIds[] = $order->customerId;
                foreach ($order->items as $item) {
                    $rows[] = [
                        'occurred_on' => $order->occurredOn,
                        'order' => '#'.$order->id,
                        'customer' => $customerNames[$order->customerId] ?? __('institution_sales.fallbacks.missing_customer'),
                        'project' => $item->projectName,
                        'quantity' => $item->quantity,
                        'amount' => $item->amountKrw,
                        'notes' => $item->notes,
                    ];
                }
            }
            $sections[] = new FinancialDocumentSectionData(
                title: $row->institutionName,
                rows: $rows,
                summaryRows: [
                    ['label' => __('institution_sales.table.total_customers'), 'value' => count(array_unique($customerIds)), 'type' => 'number'],
                    ['label' => __('institution_sales.table.total_orders'), 'value' => count($orders), 'type' => 'number'],
                    ['label' => __('institution_sales.table.total_amount'), 'value' => $row->amountKrw, 'type' => 'amount', 'emphasis' => true],
                ],
            );
        }
        $summaryRows = $institutionName === '' ? [
            ['label' => __('institution_sales.table.total_customers'), 'value' => $summary->totalCustomers, 'type' => 'number'],
            ['label' => __('institution_sales.table.total_orders'), 'value' => $summary->totalOrders, 'type' => 'number'],
            ['label' => __('institution_sales.table.total_amount'), 'value' => $summary->totalAmountKrw, 'type' => 'amount', 'emphasis' => true],
        ] : [];

        return new FinancialDocumentData(
            title: __('institution_sales.title'),
            documentNumber: 'IMS-'.str_replace('-', '', $summary->month).'-'.$export->id,
            documentDate: now()->toDateString(),
            subject: $institutionName === '' ? __('institution_sales.export.all_institutions') : $institutionName,
            period: $summary->from->toDateString().' — '.$summary->to->toDateString(),
            primaryAmount: $summary->totalAmountKrw,
            currency: 'KRW',
            metadata: [],
            columns: $columns,
            rows: [],
            summaryRows: $summaryRows,
            remarks: [__('institution_sales.scope_note')],
            primaryAmountLabel: __('institution_sales.export.primary_amount'),
            currencyDecimals: 0,
            subjectLabel: __('institution_sales.fields.institution'),
            sections: $sections,
        );
    }
}
