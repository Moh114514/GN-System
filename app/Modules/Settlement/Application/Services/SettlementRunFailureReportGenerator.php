<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

final readonly class SettlementRunFailureReportGenerator
{
    public function __construct(private SettlementRunFailureReader $reader) {}

    public function generate(SettlementRun $run): string
    {
        $spreadsheet = new Spreadsheet;
        $directory = 'reports/settlement-failures/'.$run->id;
        $path = $directory.'/'.Str::uuid().'.xlsx';
        $disk = Storage::disk('local');
        $absolutePath = $disk->path($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('失败明细');
            $sheet->fromArray([[
                '批次编号',
                '周期',
                '代理商编号',
                '代理商名称',
                '代理商 ID',
                '失败原因',
            ]]);
            foreach ($this->reader->read($run) as $index => $failure) {
                $sheet->fromArray([[
                    $run->id,
                    $run->period_start->format('Y-m-d').' 至 '.$run->period_end->format('Y-m-d'),
                    $failure->agentCode,
                    $failure->agentName,
                    $failure->agentId,
                    $failure->reason,
                ]], null, 'A'.($index + 2));
            }
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:F'.max(1, count($run->errors ?? []) + 1));
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);
            $sheet->getStyle('F:F')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getColumnDimension('A')->setWidth(38);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(20);
            $sheet->getColumnDimension('D')->setWidth(24);
            $sheet->getColumnDimension('E')->setWidth(12);
            $sheet->getColumnDimension('F')->setWidth(60);

            $disk->makeDirectory($directory);
            $reportDirectory = dirname($absolutePath);
            if (! is_dir($reportDirectory) || ! is_writable($reportDirectory)) {
                throw new RuntimeException('报告目录不可写，请联系管理员。');
            }
            (new Xlsx($spreadsheet))->save($absolutePath);
            if (! is_file($absolutePath)) {
                throw new RuntimeException('报告文件未生成，请联系管理员。');
            }

            return $path;
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            throw $exception;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }
}
