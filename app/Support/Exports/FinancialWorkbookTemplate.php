<?php

namespace App\Support\Exports;

use App\Support\Exports\DTO\FinancialDocumentData;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class FinancialWorkbookTemplate
{
    private const PDF_CACHE_PATH = 'framework/cache/dompdf';

    public function spreadsheet(FinancialDocumentData $document): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($document->title));
        $columnCount = max(1, count($document->columns));
        $lastColumn = $this->columnName($columnCount);
        $row = 1;

        $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
        $sheet->setCellValue("A{$row}", $document->title);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => FinancialWorkbookStyle::ACCENT_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
        $row += 2;

        $metadata = [
            ['label' => __('exports.formal_document.subject'), 'value' => $document->subject],
            ['label' => __('exports.formal_document.period'), 'value' => $document->period],
            ['label' => __('exports.formal_document.document_date'), 'value' => $document->documentDate],
            ['label' => __('exports.formal_document.document_number'), 'value' => $document->documentNumber],
            ...$document->metadata,
        ];
        for ($index = 0; $index < count($metadata); $index += 2) {
            $left = $metadata[$index];
            $right = $metadata[$index + 1] ?? null;
            $this->setLabelValuePair($sheet, $row, $left, $right, $lastColumn);
            $row++;
        }

        $primaryLabel = $document->primaryAmountLabel ?? __('exports.formal_document.primary_amount');
        $sheet->mergeCells("A{$row}:".($columnCount > 1 ? $this->columnName($columnCount - 1) : $lastColumn).$row);
        $primaryValueColumn = $columnCount > 1 ? $lastColumn : 'A';
        $sheet->setCellValue("A{$row}", $primaryLabel);
        $sheet->setCellValue("{$primaryValueColumn}{$row}", $document->primaryAmount);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => FinancialWorkbookStyle::PALE_CYAN]],
            'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => FinancialWorkbookStyle::ACCENT_DARK]],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => FinancialWorkbookStyle::ACCENT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $this->formatAmountCell($sheet, "{$primaryValueColumn}{$row}", $document->currency, $document->currencyDecimals);
        $sheet->getRowDimension($row)->setRowHeight(26);
        $row += 2;

        $tableHeaderRow = $row;
        foreach ($document->columns as $index => $column) {
            $coordinate = $this->columnName($index + 1).$row;
            $this->setString($sheet, $coordinate, (string) $column['label']);
            $sheet->getColumnDimension($this->columnName($index + 1))->setWidth((float) ($column['width'] ?? $this->defaultWidth((string) ($column['type'] ?? 'text'))));
        }
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => FinancialWorkbookStyle::ACCENT]],
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => FinancialWorkbookStyle::BORDER]]],
        ]);
        $row++;

        foreach ($document->rows as $item) {
            foreach ($document->columns as $index => $column) {
                $coordinate = $this->columnName($index + 1).$row;
                $value = $item[$column['key']] ?? null;
                $type = (string) ($column['type'] ?? 'text');
                $this->setTypedValue($sheet, $coordinate, $value, $type, $document->currency, $document->currencyDecimals);
            }
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(FinancialWorkbookStyle::BORDER);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $row++;
        }
        if ($document->rows === []) {
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $this->setString($sheet, "A{$row}", __('exports.formal_document.no_items'));
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $row++;
        foreach ($document->summaryRows as $summary) {
            $sheet->mergeCells("A{$row}:".($columnCount > 1 ? $this->columnName($columnCount - 1) : $lastColumn).$row);
            $valueColumn = $columnCount > 1 ? $lastColumn : 'A';
            $this->setString($sheet, "A{$row}", (string) $summary['label']);
            $type = (string) ($summary['type'] ?? 'amount');
            $currency = (string) ($summary['currency'] ?? $document->currency);
            $this->setTypedValue($sheet, "{$valueColumn}{$row}", $summary['value'] ?? null, $type, $currency, $document->currencyDecimals);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => FinancialWorkbookStyle::PALE_CYAN]],
                'font' => ['bold' => (bool) ($summary['emphasis'] ?? false), 'color' => ['rgb' => FinancialWorkbookStyle::TEXT]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => FinancialWorkbookStyle::BORDER]]],
            ]);
            $row++;
        }

        if ($document->remarks !== []) {
            $row++;
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $this->setString($sheet, "A{$row}", __('exports.formal_document.remarks'));
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => FinancialWorkbookStyle::ACCENT_DARK]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'fgColor' => ['rgb' => FinancialWorkbookStyle::PALE_CYAN]],
            ]);
            foreach ($document->remarks as $remark) {
                $row++;
                $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
                $this->setString($sheet, "A{$row}", $remark);
                $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setWrapText(true);
            }
        }

        $sheet->freezePane('A'.($tableHeaderRow + 1));
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        $sheet->getPageSetup()->setOrientation($columnCount >= 7 ? PageSetup::ORIENTATION_LANDSCAPE : PageSetup::ORIENTATION_PORTRAIT);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($tableHeaderRow, $tableHeaderRow);
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.3)->setBottom(0.35)->setLeft(0.3);
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$row}");
        $sheet->getStyle("A1:{$lastColumn}{$row}")->getFont()->setName('Arial');
        $sheet->getStyle("A1:{$lastColumn}{$row}")->getFont()->setColor(new Color(FinancialWorkbookStyle::TEXT));

        return $spreadsheet;
    }

    public function writeXlsx(FinancialDocumentData $document, string $path): void
    {
        $spreadsheet = $this->spreadsheet($document);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    public function renderPdf(FinancialDocumentData $document): string
    {
        $pdfRegularFontPath = (string) config('reporting.pdf.font_regular_path');
        $pdfBoldFontPath = (string) config('reporting.pdf.font_bold_path');
        if (! is_readable($pdfRegularFontPath) || ! is_readable($pdfBoldFontPath)) {
            throw new RuntimeException(__('settlements.errors.document_pdf_font_missing'));
        }

        $fontCachePath = storage_path(self::PDF_CACHE_PATH.'/fonts');
        $tempPath = storage_path(self::PDF_CACHE_PATH.'/temp');
        File::ensureDirectoryExists($fontCachePath);
        File::ensureDirectoryExists($tempPath);
        if (! is_writable($fontCachePath) || ! is_writable($tempPath)) {
            throw new RuntimeException(__('settlements.errors.document_pdf_cache_unwritable'));
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setChroot([base_path(), dirname($pdfRegularFontPath), dirname($pdfBoldFontPath)]);
        $options->setDefaultFont('GN CJK');
        $options->setIsFontSubsettingEnabled(false);
        $options->setFontDir($fontCachePath);
        $options->setFontCache($fontCachePath);
        $options->setTempDir($tempPath);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($document, $pdfRegularFontPath, $pdfBoldFontPath), 'UTF-8');
        $dompdf->setPaper('A4', count($document->columns) >= 7 ? 'landscape' : 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function html(FinancialDocumentData $document, string $pdfRegularFontPath, string $pdfBoldFontPath): string
    {
        $metadataRows = '';
        $metadataItems = [
            ['label' => __('exports.formal_document.subject'), 'value' => $document->subject],
            ['label' => __('exports.formal_document.period'), 'value' => $document->period],
            ['label' => __('exports.formal_document.document_date'), 'value' => $document->documentDate],
            ['label' => __('exports.formal_document.document_number'), 'value' => $document->documentNumber],
            ...$document->metadata,
        ];
        for ($index = 0; $index < count($metadataItems); $index += 2) {
            $left = $metadataItems[$index];
            $right = $metadataItems[$index + 1] ?? null;
            $metadataRows .= '<tr><td class="meta"><span>'.e((string) $left['label']).'</span><strong>'.e((string) $left['value']).'</strong></td>';
            $metadataRows .= $right === null
                ? '<td class="meta"></td>'
                : '<td class="meta"><span>'.e((string) $right['label']).'</span><strong>'.e((string) $right['value']).'</strong></td>';
            $metadataRows .= '</tr>';
        }
        $headers = '';
        foreach ($document->columns as $column) {
            $headers .= '<th>'.e((string) $column['label']).'</th>';
        }
        $rows = '';
        foreach ($document->rows as $item) {
            $rows .= '<tr>';
            foreach ($document->columns as $column) {
                $rows .= '<td class="'.e((string) ($column['type'] ?? 'text')).'">'.$this->htmlValue($item[$column['key']] ?? null, (string) ($column['type'] ?? 'text'), $document->currency, $document->currencyDecimals).'</td>';
            }
            $rows .= '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td class="empty" colspan="'.max(1, count($document->columns)).'">'.e(__('exports.formal_document.no_items')).'</td></tr>';
        }
        $summary = '<table class="summary"><tbody>';
        foreach ($document->summaryRows as $item) {
            $currency = (string) ($item['currency'] ?? $document->currency);
            $type = (string) ($item['type'] ?? 'amount');
            $summary .= '<tr class="'.(! empty($item['emphasis']) ? 'emphasis' : '').'"><td class="summary-label">'.e((string) $item['label']).'</td><td class="summary-value">'.$this->htmlValue($item['value'] ?? null, $type, $currency, $document->currencyDecimals).'</td></tr>';
        }
        $summary .= '</tbody></table>';
        $remarks = $document->remarks === [] ? '' : '<div class="remarks"><h3>'.e(__('exports.formal_document.remarks')).'</h3>'.implode('', array_map(fn (string $remark): string => '<p>'.e($remark).'</p>', $document->remarks)).'</div>';
        $primary = $this->htmlValue($document->primaryAmount, 'amount', $document->currency, $document->currencyDecimals);
        $css = '@font-face{font-family:"GN CJK";font-style:normal;font-weight:400;src:url("file://'.e($pdfRegularFontPath).'") format("truetype");}'
            .'@font-face{font-family:"GN CJK";font-style:normal;font-weight:700;src:url("file://'.e($pdfBoldFontPath).'") format("truetype");}'
            .'@page{margin:14mm 12mm 14mm 12mm;}body{font-family:"GN CJK",Arial,sans-serif;color:#'.FinancialWorkbookStyle::TEXT.';font-size:10.5px;font-weight:400;line-height:1.35;}h1{color:#'.FinancialWorkbookStyle::ACCENT_DARK.';font-size:24px;font-weight:700;line-height:1.2;text-align:center;margin:0 0 18px;}'
            .'table{width:100%;border-collapse:collapse;margin-bottom:12px;page-break-inside:auto}thead{display:table-header-group}tr{page-break-inside:avoid}'
            .'.meta-grid{table-layout:fixed;border:1px solid #'.FinancialWorkbookStyle::BORDER.';margin-bottom:14px}.meta-grid td{width:50%;box-sizing:border-box}.meta{padding:7px 9px;border-bottom:1px solid #'.FinancialWorkbookStyle::BORDER.'}.meta:first-child{border-right:1px solid #'.FinancialWorkbookStyle::BORDER.'}.meta span{display:block;color:#'.FinancialWorkbookStyle::MUTED.';font-size:9.5px;font-weight:400;line-height:1.25;margin-bottom:2px}.meta strong{font-size:11px;font-weight:700;line-height:1.3}'
            .'.primary{background:#'.FinancialWorkbookStyle::PALE_CYAN.';border:2px solid #'.FinancialWorkbookStyle::ACCENT.';padding:12px 14px;margin-bottom:16px;text-align:center}.primary span{display:block;color:#'.FinancialWorkbookStyle::ACCENT_DARK.';font-weight:700;font-size:11px}.primary strong{display:block;color:#'.FinancialWorkbookStyle::ACCENT_DARK.';font-size:22px;font-weight:700;line-height:1.2;margin-top:4px}'
            .'h2{font-size:13px;font-weight:700;color:#'.FinancialWorkbookStyle::ACCENT_DARK.';border-bottom:2px solid #'.FinancialWorkbookStyle::ACCENT.';padding-bottom:5px;margin:0 0 7px;}th{background:#'.FinancialWorkbookStyle::ACCENT.';color:#fff;font-size:10.5px;font-weight:700;line-height:1.25;padding:7px 6px;border:1px solid #'.FinancialWorkbookStyle::BORDER.'}td{font-size:10.25px;font-weight:400;line-height:1.3;padding:5.5px 6px;border:1px solid #'.FinancialWorkbookStyle::BORDER.';vertical-align:top}td.amount,td.percent{text-align:right;white-space:nowrap;font-weight:700}td.empty{text-align:center;color:#'.FinancialWorkbookStyle::MUTED.'}'
            .'.summary{margin-top:10px;background:#'.FinancialWorkbookStyle::PALE_CYAN.';border:1px solid #'.FinancialWorkbookStyle::BORDER.'}.summary td{font-size:10.5px;padding:7px 9px}.summary-label{width:70%}.summary-value{text-align:right;white-space:nowrap}.summary tr.emphasis td{border:2px solid #'.FinancialWorkbookStyle::ACCENT.';color:#'.FinancialWorkbookStyle::ACCENT_DARK.';font-size:12.5px;font-weight:700;padding-top:8px;padding-bottom:8px}'
            .'.remarks{margin-top:14px;border-top:1px solid #'.FinancialWorkbookStyle::BORDER.';padding-top:8px}.remarks h3{color:#'.FinancialWorkbookStyle::ACCENT_DARK.';font-size:11px}.remarks p{margin:4px 0;color:#'.FinancialWorkbookStyle::MUTED.'}';

        return '<!doctype html><html lang="'.e(str_replace('_', '-', app()->getLocale())).'"><head><meta charset="UTF-8"><style>'.$css
            .'</style></head><body><h1>'.e($document->title).'</h1><table class="meta-grid"><tbody>'.$metadataRows.'</tbody></table><div class="primary"><span>'.e($document->primaryAmountLabel ?? __('exports.formal_document.primary_amount')).'</span><strong>'.$primary.'</strong></div><h2>'.e(__('exports.formal_document.details')).'</h2><table><thead><tr>'.$headers.'</tr></thead><tbody>'.$rows.'</tbody></table>'.$summary.$remarks.'</body></html>';
    }

    /**
     * @param  array{label: string, value: scalar|null}  $left
     * @param  array{label: string, value: scalar|null}|null  $right
     */
    private function setLabelValuePair(Worksheet $sheet, int $row, array $left, ?array $right, string $lastColumn): void
    {
        $this->setString($sheet, "A{$row}", $left['label']);
        $this->setString($sheet, 'B'.$row, (string) $left['value']);
        if ($right !== null) {
            $mid = $this->columnName(max(3, $this->columnIndex($lastColumn) - 3));
            $this->setString($sheet, "{$mid}{$row}", $right['label']);
            $this->setString($sheet, $this->columnName($this->columnIndex($mid) + 1).$row, (string) $right['value']);
        }
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(FinancialWorkbookStyle::BORDER);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setSize(10);
    }

    private function setTypedValue(Worksheet $sheet, string $coordinate, mixed $value, string $type, string $currency, ?int $decimals): void
    {
        if ($value === null || $value === '') {
            $this->setString($sheet, $coordinate, '');

            return;
        }
        if ($type === 'amount' || $type === 'number') {
            $sheet->setCellValue($coordinate, is_numeric($value) ? (float) $value : 0);
            if ($type === 'amount') {
                $this->formatAmountCell($sheet, $coordinate, $currency, $decimals);
            }

            return;
        }
        if ($type === 'percent') {
            $sheet->setCellValue($coordinate, ((float) $value) / 10000);
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('0.00%');

            return;
        }
        $this->setString($sheet, $coordinate, (string) $value);
    }

    private function formatAmountCell(Worksheet $sheet, string $coordinate, string $currency, ?int $decimals): void
    {
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(FinancialWorkbookStyle::numberFormat($currency, $decimals));
        $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function setString(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
    }

    private function htmlValue(mixed $value, string $type, string $currency, ?int $decimals): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($type === 'amount') {
            return e(FinancialWorkbookStyle::currencySymbol($currency).' '.number_format((float) $value, FinancialWorkbookStyle::decimals($currency, $decimals)));
        }
        if ($type === 'percent') {
            return e(number_format(((float) $value) / 100, 2).'%');
        }

        return e((string) $value);
    }

    private function defaultWidth(string $type): float
    {
        return match ($type) {
            'amount', 'percent' => 15,
            'date' => 14,
            default => 22,
        };
    }

    private function sheetTitle(string $title): string
    {
        return mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $title) ?: 'Financial document', 0, 31);
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function columnIndex(string $name): int
    {
        $index = 0;
        foreach (str_split($name) as $character) {
            $index = $index * 26 + ord($character) - 64;
        }

        return $index;
    }
}
