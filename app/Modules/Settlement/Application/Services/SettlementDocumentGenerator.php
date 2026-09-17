<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Infrastructure\Models\Settlement;
use App\Modules\Settlement\Infrastructure\Models\SettlementDocument;
use App\Modules\Settlement\Infrastructure\Models\SettlementRunMember;
use App\Support\Exports\DTO\FinancialDocumentData;
use App\Support\Exports\FinancialWorkbookStyle;
use App\Support\Exports\FinancialWorkbookTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

final class SettlementDocumentGenerator
{
    public function __construct(private FinancialWorkbookTemplate $template) {}

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
                    'completed_on' => data_get($snapshot, 'order.completed_on') ?: data_get($snapshot, 'order.occurred_on'),
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
        $data = $this->viewModel($settlement);
        $document = $this->documentData($settlement, $data);
        $directory = "settlements/{$settlement->id}";
        Storage::disk('local')->makeDirectory($directory);

        $wordPath = "{$directory}/settlement-{$settlement->id}.docx";
        IOFactory::createWriter($this->word($document), 'Word2007')->save(Storage::disk('local')->path($wordPath));
        $this->record($settlement, 'docx', $wordPath, $data);

        $xlsxPath = "{$directory}/settlement-{$settlement->id}.xlsx";
        $this->template->writeXlsx($document, Storage::disk('local')->path($xlsxPath));
        $this->record($settlement, 'xlsx', $xlsxPath, $data);

        $pdfPath = "{$directory}/settlement-{$settlement->id}.pdf";
        Storage::disk('local')->put($pdfPath, $this->template->renderPdf($document));
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
            ->whereIn('settlement_id', SettlementRunMember::query()
                ->where('settlement_run_id', $runId)
                ->whereNotNull('settlement_id')
                ->pluck('settlement_id'))
            ->orderBy('settlement_id')
            ->orderBy('format')
            ->get();
        $path = "settlements/runs/{$runId}.zip";
        Storage::disk('local')->makeDirectory('settlements/runs');
        $archive = new ZipArchive;
        $archive->open(Storage::disk('local')->path($path), ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($documents as $document) {
            if (Storage::disk('local')->exists($document->path)) {
                $archive->addFile(Storage::disk('local')->path($document->path), "settlement-{$document->settlement_id}.{$document->format}");
            }
        }
        $archive->close();

        return $path;
    }

    /** @param array<string, mixed> $data */
    private function documentData(Settlement $settlement, array $data): FinancialDocumentData
    {
        $metadata = [
            ['label' => __('settlements.documents.agent_label'), 'value' => $data['agent_name'].'（'.$data['agent_code'].'）'],
            ['label' => __('settlements.documents.status'), 'value' => __('settlements.settlement_statuses.'.$settlement->status)],
            ['label' => __('settlements.documents.currency'), 'value' => (string) ($settlement->settlement_currency ?: 'KRW')],
        ];
        if ($settlement->exchange_rate_krw_per_cny !== null) {
            $metadata[] = ['label' => __('settlements.documents.exchange_rate_label'), 'value' => (string) $settlement->exchange_rate_krw_per_cny.' KRW/CNY'];
        }

        $summaryRows = [
            ['label' => __('settlements.documents.total_consumption_label'), 'value' => $data['total_consumption_krw'], 'type' => 'amount'],
            ['label' => __('settlements.documents.total_commission_label'), 'value' => $data['total_commission_krw'], 'type' => 'amount', 'emphasis' => true],
        ];
        if ((string) ($settlement->settlement_currency ?: 'KRW') === 'CNY') {
            $summaryRows[] = ['label' => __('settlements.documents.payable_label'), 'value' => $data['payout_amount_cny_fen'] / 100, 'type' => 'amount', 'currency' => 'CNY', 'emphasis' => true];
        }

        return new FinancialDocumentData(
            title: __('settlements.documents.title'),
            documentNumber: 'SET-'.str_replace('-', '', $data['period_start']).'-'.$settlement->id,
            documentDate: ($settlement->generated_at ?? now())->format('Y-m-d'),
            subject: $data['agent_name'],
            period: $data['period_start'].' — '.$data['period_end'],
            primaryAmount: $data['total_commission_krw'],
            currency: 'KRW',
            metadata: $metadata,
            columns: [
                ['key' => 'order_id', 'label' => __('settlements.documents.headers.order'), 'type' => 'text', 'width' => 13],
                ['key' => 'completed_on', 'label' => __('settlements.documents.headers.completed_on'), 'type' => 'date', 'width' => 14],
                ['key' => 'project_name', 'label' => __('settlements.documents.headers.project'), 'type' => 'text', 'width' => 30],
                ['key' => 'consumption_krw', 'label' => __('settlements.documents.headers.consumption'), 'type' => 'amount', 'width' => 17],
                ['key' => 'rate_bps', 'label' => __('settlements.documents.headers.rate'), 'type' => 'percent', 'width' => 12],
                ['key' => 'commission_krw', 'label' => __('settlements.documents.headers.commission'), 'type' => 'amount', 'width' => 17],
            ],
            rows: $data['items'],
            summaryRows: $summaryRows,
            remarks: [__('settlements.documents.remark_status', ['status' => __('settlements.settlement_statuses.'.$settlement->status)])],
            primaryAmountLabel: __('settlements.documents.primary_amount'),
            currencyDecimals: 0,
        );
    }

    private function word(FinancialDocumentData $document): PhpWord
    {
        $word = new PhpWord;
        $section = $word->addSection(['marginTop' => 720, 'marginBottom' => 720, 'marginLeft' => 720, 'marginRight' => 720]);
        $section->addTitle($document->title, 1);
        $section->addText($document->primaryAmountLabel.': '.FinancialWorkbookStyle::currencySymbol($document->currency).' '.number_format((float) $document->primaryAmount, FinancialWorkbookStyle::decimals($document->currency, $document->currencyDecimals)));
        $section->addText(__('exports.formal_document.document_number').': '.$document->documentNumber);
        $section->addText(__('exports.formal_document.document_date').': '.$document->documentDate);
        foreach ($document->metadata as $item) {
            $section->addText($item['label'].': '.$item['value']);
        }
        $section->addText(__('exports.formal_document.period').': '.$document->period);
        $table = $section->addTable(['borderSize' => 6, 'cellMargin' => 60]);
        $table->addRow();
        foreach ($document->columns as $column) {
            $table->addCell()->addText($column['label']);
        }
        foreach ($document->rows as $item) {
            $table->addRow();
            foreach ($document->columns as $column) {
                $value = $item[$column['key']] ?? '';
                if ($column['type'] === 'amount') {
                    $value = number_format((float) $value, FinancialWorkbookStyle::decimals($document->currency, $document->currencyDecimals));
                } elseif ($column['type'] === 'percent') {
                    $value = number_format(((float) $value) / 100, 2).'%';
                }
                $table->addCell()->addText((string) $value);
            }
        }
        foreach ($document->summaryRows as $summary) {
            $currency = (string) ($summary['currency'] ?? $document->currency);
            $decimals = FinancialWorkbookStyle::decimals($currency, $currency === 'CNY' ? 2 : $document->currencyDecimals);
            $section->addText($summary['label'].': '.FinancialWorkbookStyle::currencySymbol($currency).' '.number_format((float) $summary['value'], $decimals));
        }
        foreach ($document->remarks as $remark) {
            $section->addText(__('exports.formal_document.remarks').': '.$remark);
        }

        return $word;
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
}
