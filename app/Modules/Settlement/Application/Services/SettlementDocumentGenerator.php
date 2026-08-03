<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use ZipArchive;

final class SettlementDocumentGenerator
{
    private const PDF_FONT_PATH = '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf';

    private const PDF_CACHE_PATH = 'framework/cache/dompdf';

    /** @return array<string, mixed> */
    public function viewModel(Settlement $settlement): array
    {
        $items = DB::table('settlement_items')
            ->where('settlement_id', $settlement->id)
            ->orderBy('id')
            ->get()
            ->map(function ($item): array {
                $snapshot = is_string($item->rule_snapshot)
                    ? json_decode($item->rule_snapshot, true, 512, JSON_THROW_ON_ERROR)
                    : (array) $item->rule_snapshot;

                return [
                    'order_id' => data_get($snapshot, 'order.id'),
                    'completed_on' => data_get($snapshot, 'order.completed_on'),
                    'project_name' => data_get($snapshot, 'order.project_name'),
                    'rate_bps' => data_get($snapshot, 'rate_bps'),
                    'consumption_krw' => (int) $item->consumption_krw,
                    'commission_krw' => (int) $item->commission_krw,
                ];
            })
            ->all();
        $snapshot = $settlement->snapshot ?? [];

        return [
            'settlement_id' => (int) $settlement->id,
            'agent_code' => (string) data_get($snapshot, 'agent.code', '未知'),
            'agent_name' => (string) data_get($snapshot, 'agent.name', '未知代理商'),
            'period_start' => $settlement->period_start->format('Y-m-d'),
            'period_end' => $settlement->period_end->format('Y-m-d'),
            'exchange_rate' => (string) $settlement->exchange_rate_krw_per_cny,
            'total_consumption_krw' => (int) $settlement->total_consumption_krw,
            'total_commission_krw' => (int) $settlement->total_commission_krw,
            'payout_amount_cny_fen' => (int) $settlement->payout_amount_cny_fen,
            'items' => $items,
        ];
    }

    public function generate(Settlement $settlement): void
    {
        $dompdf = $this->dompdf();
        $data = $this->viewModel($settlement);
        $directory = "settlements/{$settlement->id}";
        Storage::disk('local')->makeDirectory($directory);

        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTitle('代理商月结结算单', 1);
        $section->addText("代理商：{$data['agent_name']}（{$data['agent_code']}）");
        $section->addText("结算周期：{$data['period_start']} 至 {$data['period_end']}");
        $table = $section->addTable(['borderSize' => 6, 'cellMargin' => 60]);
        $table->addRow();
        foreach (['订单', '完成日期', '项目', '消费额 KRW', '费率', '推广费 KRW'] as $heading) {
            $table->addCell()->addText($heading);
        }
        foreach ($data['items'] as $item) {
            $table->addRow();
            foreach ([
                $item['order_id'],
                $item['completed_on'],
                $item['project_name'],
                number_format($item['consumption_krw']),
                number_format(((int) $item['rate_bps']) / 100, 2).'%',
                number_format($item['commission_krw']),
            ] as $value) {
                $table->addCell()->addText((string) $value);
            }
        }
        $section->addText('消费合计：₩ '.number_format($data['total_consumption_krw']));
        $section->addText('推广费合计：₩ '.number_format($data['total_commission_krw']));
        $section->addText('结算汇率：'.number_format((float) $data['exchange_rate'], 6).' KRW/CNY');
        $section->addText('应付金额：¥ '.number_format($data['payout_amount_cny_fen'] / 100, 2));

        $wordPath = "{$directory}/settlement-{$settlement->id}.docx";
        $wordAbsolute = Storage::disk('local')->path($wordPath);
        IOFactory::createWriter($word, 'Word2007')->save($wordAbsolute);
        $this->record($settlement, 'docx', $wordPath, $data);

        $dompdf->loadHtml($this->html($data, self::PDF_FONT_PATH), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfPath = "{$directory}/settlement-{$settlement->id}.pdf";
        Storage::disk('local')->put($pdfPath, $dompdf->output());
        $this->record($settlement, 'pdf', $pdfPath, $data);
    }

    public function archiveRun(string $runId): string
    {
        $documents = SettlementDocument::query()
            ->whereIn('settlement_id', Settlement::query()->where('settlement_run_id', $runId)->pluck('id'))
            ->orderBy('settlement_id')
            ->orderBy('format')
            ->get();
        $path = "settlements/runs/{$runId}.zip";
        Storage::disk('local')->makeDirectory('settlements/runs');
        $archive = new ZipArchive;
        $archive->open(Storage::disk('local')->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($documents as $document) {
            if (Storage::disk('local')->exists($document->path)) {
                $archive->addFile(
                    Storage::disk('local')->path($document->path),
                    "settlement-{$document->settlement_id}.{$document->format}",
                );
            }
        }
        $archive->close();

        return $path;
    }

    /** @param array<string, mixed> $data */
    private function record(Settlement $settlement, string $format, string $path, array $data): void
    {
        SettlementDocument::query()->updateOrCreate(
            ['settlement_id' => $settlement->id, 'format' => $format],
            [
                'path' => $path,
                'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
                'content_snapshot' => $data,
                'generated_at' => now(),
            ],
        );
    }

    private function dompdf(): Dompdf
    {
        if (! is_readable(self::PDF_FONT_PATH)) {
            throw new RuntimeException('结算 PDF 中文字体不可用，请重新构建应用镜像后重试。');
        }

        $fontCachePath = storage_path(self::PDF_CACHE_PATH.'/fonts');
        $tempPath = storage_path(self::PDF_CACHE_PATH.'/temp');
        File::ensureDirectoryExists($fontCachePath);
        File::ensureDirectoryExists($tempPath);
        if (! is_writable($fontCachePath) || ! is_writable($tempPath)) {
            throw new RuntimeException('结算 PDF 缓存目录不可写，请检查 storage 目录权限后重试。');
        }

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setChroot([base_path(), dirname(self::PDF_FONT_PATH)]);
        $options->setDefaultFont('GN CJK');
        $options->setIsFontSubsettingEnabled(false);
        $options->setFontDir($fontCachePath);
        $options->setFontCache($fontCachePath);
        $options->setTempDir($tempPath);

        return new Dompdf($options);
    }

    /** @param array<string, mixed> $data */
    private function html(array $data, string $pdfFontPath): string
    {
        $rows = '';
        foreach ($data['items'] as $item) {
            $rows .= '<tr><td>'.e((string) $item['order_id']).'</td><td>'.e((string) $item['completed_on'])
                .'</td><td>'.e((string) $item['project_name']).'</td><td>'.number_format($item['consumption_krw'])
                .'</td><td>'.number_format(((int) $item['rate_bps']) / 100, 2).'%</td><td>'
                .number_format($item['commission_krw']).'</td></tr>';
        }

        return '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"><style>'
            .'@font-face{font-family:"GN CJK";font-style:normal;font-weight:normal;src:url("file://'.e($pdfFontPath).'") format("truetype");}'
            .'body{font-family:"GN CJK","Microsoft YaHei","PingFang SC","Noto Sans CJK SC",DejaVu Sans,sans-serif;color:#222}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:6px;text-align:left}'
            .'</style></head><body><h1>代理商月结结算单</h1><p>代理商：'.e($data['agent_name']).'（'.e($data['agent_code']).'）</p>'
            .'<p>结算周期：'.$data['period_start'].' 至 '.$data['period_end'].'</p><table><thead><tr>'
            .'<th>订单</th><th>完成日期</th><th>项目</th><th>消费额 KRW</th><th>费率</th><th>推广费 KRW</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table><p>消费合计：₩ '.number_format($data['total_consumption_krw'])
            .'</p><p>推广费合计：₩ '.number_format($data['total_commission_krw']).'</p><p>结算汇率：'
            .number_format((float) $data['exchange_rate'], 6).' KRW/CNY</p><p>应付金额：¥ '
            .number_format($data['payout_amount_cny_fen'] / 100, 2).'</p></body></html>';
    }
}
