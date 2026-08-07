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
            'agent_code' => (string) data_get($snapshot, 'agent.code', __('settlements.documents.unknown')),
            'agent_name' => (string) data_get($snapshot, 'agent.name', __('settlements.documents.unknown_agent')),
            'period_start' => $settlement->period_start->format('Y-m-d'),
            'period_end' => $settlement->period_end->format('Y-m-d'),
            'exchange_rate' => (string) $settlement->exchange_rate_krw_per_cny,
            'total_consumption_krw' => (int) $settlement->total_consumption_krw,
            'total_commission_krw' => (int) $settlement->total_commission_krw,
            'payout_amount_cny_fen' => (int) $settlement->payout_amount_cny_fen,
            'items' => $items,
            'locale' => app()->getLocale(),
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
        $section->addTitle(__('settlements.documents.title'), 1);
        $section->addText(__('settlements.documents.agent', ['name' => $data['agent_name'], 'code' => $data['agent_code']]));
        $section->addText(__('settlements.documents.period', ['from' => $data['period_start'], 'to' => $data['period_end']]));
        $table = $section->addTable(['borderSize' => 6, 'cellMargin' => 60]);
        $table->addRow();
        foreach (array_values(__('settlements.documents.headers')) as $heading) {
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
        $section->addText(__('settlements.documents.total_consumption', ['amount' => number_format($data['total_consumption_krw'])]));
        $section->addText(__('settlements.documents.total_commission', ['amount' => number_format($data['total_commission_krw'])]));
        $section->addText(__('settlements.documents.exchange_rate', ['rate' => number_format((float) $data['exchange_rate'], 6)]));
        $section->addText(__('settlements.documents.payable', ['amount' => number_format($data['payout_amount_cny_fen'] / 100, 2)]));

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

    public function discard(int $settlementId): int
    {
        $documents = SettlementDocument::query()->where('settlement_id', $settlementId)->get();
        foreach ($documents as $document) {
            if (Storage::disk('local')->exists($document->path)) {
                Storage::disk('local')->delete($document->path);
            }
        }
        SettlementDocument::query()->where('settlement_id', $settlementId)->delete();

        return $documents->count();
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

        $labels = __('settlements.documents');
        $agent = e(__('settlements.documents.agent', [
            'name' => $data['agent_name'],
            'code' => $data['agent_code'],
        ]));
        $period = e(__('settlements.documents.period', [
            'from' => $data['period_start'],
            'to' => $data['period_end'],
        ]));
        $totalConsumption = e(__('settlements.documents.total_consumption', [
            'amount' => number_format($data['total_consumption_krw']),
        ]));
        $totalCommission = e(__('settlements.documents.total_commission', [
            'amount' => number_format($data['total_commission_krw']),
        ]));
        $exchangeRate = e(__('settlements.documents.exchange_rate', [
            'rate' => number_format((float) $data['exchange_rate'], 6),
        ]));
        $payable = e(__('settlements.documents.payable', [
            'amount' => number_format($data['payout_amount_cny_fen'] / 100, 2),
        ]));

        return '<!doctype html><html lang="'.str_replace('_', '-', app()->getLocale()).'"><head><meta charset="UTF-8"><style>'
            .'@font-face{font-family:"GN CJK";font-style:normal;font-weight:normal;src:url("file://'.e($pdfFontPath).'") format("truetype");}'
            .'body{font-family:"GN CJK","Microsoft YaHei","PingFang SC","Noto Sans CJK SC",DejaVu Sans,sans-serif;color:#222}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:6px;text-align:left}'
            .'</style></head><body><h1>'.e($labels['title']).'</h1><p>'.$agent.'</p>'
            .'<p>'.$period.'</p><table><thead><tr>'
            .'<th>'.e($labels['headers']['order']).'</th><th>'.e($labels['headers']['completed_on']).'</th><th>'.e($labels['headers']['project']).'</th><th>'.e($labels['headers']['consumption']).'</th><th>'.e($labels['headers']['rate']).'</th><th>'.e($labels['headers']['commission']).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table><p>'.$totalConsumption
            .'</p><p>'.$totalCommission.'</p><p>'.$exchangeRate.'</p><p>'.$payable.'</p></body></html>';
    }
}
