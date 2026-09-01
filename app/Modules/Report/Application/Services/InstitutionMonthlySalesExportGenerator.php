<?php

namespace App\Modules\Report\Application\Services;

use App\Modules\Report\Application\Data\InstitutionMonthlySalesSummaryData;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use App\Support\Exports\DTO\FinancialDocumentData;
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

    public function generate(ReportExport $export, InstitutionMonthlySalesSummaryData $summary): ReportExport
    {
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
                $disk->put($path, $this->template->renderPdf($this->document($summary, $export)));
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

    private function document(InstitutionMonthlySalesSummaryData $summary, ReportExport $export): FinancialDocumentData
    {
        $filter = trim((string) ($export->criteria_snapshot['institution_search'] ?? ''));
        $metadata = $filter === '' ? [] : [[
            'label' => __('institution_sales.export.institution_filter'),
            'value' => $filter,
        ]];
        $rows = [];
        foreach ($summary->rows as $index => $row) {
            $rows[] = [
                'number' => $index + 1,
                'institution' => $row->institutionName,
                'customers' => $row->customerCount,
                'orders' => $row->orderCount,
                'amount' => $row->amountKrw,
            ];
        }

        return new FinancialDocumentData(
            title: __('institution_sales.title'),
            documentNumber: 'IMS-'.str_replace('-', '', $summary->month).'-'.$export->id,
            documentDate: now()->toDateString(),
            subject: $filter === '' ? __('institution_sales.export.all_institutions') : $filter,
            period: $summary->from->toDateString().' — '.$summary->to->toDateString(),
            primaryAmount: $summary->totalAmountKrw,
            currency: 'KRW',
            metadata: $metadata,
            columns: [
                ['key' => 'number', 'label' => __('institution_sales.table.number'), 'type' => 'number', 'width' => 10],
                ['key' => 'institution', 'label' => __('institution_sales.table.institution'), 'type' => 'text', 'width' => 32],
                ['key' => 'customers', 'label' => __('institution_sales.table.customers'), 'type' => 'number', 'width' => 16],
                ['key' => 'orders', 'label' => __('institution_sales.table.orders'), 'type' => 'number', 'width' => 18],
                ['key' => 'amount', 'label' => __('institution_sales.table.amount'), 'type' => 'amount', 'width' => 20],
            ],
            rows: $rows,
            summaryRows: [
                ['label' => __('institution_sales.table.total_customers'), 'value' => $summary->totalCustomers, 'type' => 'number'],
                ['label' => __('institution_sales.table.total_orders'), 'value' => $summary->totalOrders, 'type' => 'number'],
                ['label' => __('institution_sales.table.total_amount'), 'value' => $summary->totalAmountKrw, 'type' => 'amount', 'emphasis' => true],
            ],
            remarks: [__('institution_sales.scope_note')],
            primaryAmountLabel: __('institution_sales.export.primary_amount'),
            currencyDecimals: 0,
        );
    }
}
