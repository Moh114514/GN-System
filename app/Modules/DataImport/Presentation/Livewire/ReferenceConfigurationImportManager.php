<?php

namespace App\Modules\DataImport\Presentation\Livewire;

use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportCommitter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationTemplateGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Jobs\ParseReferenceConfigurationImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Layout('layouts.app')]
#[Title('基础配置导入')]
class ReferenceConfigurationImportManager extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $workbook = null;

    public ?string $selectedBatchId = null;

    public bool $confirmImport = false;

    public function render(): View
    {
        return view('livewire.data-imports.reference-configuration-import-manager');
    }

    public function stageWorkbook(EncryptedImportStorage $storage): void
    {
        $this->validate([
            'workbook' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:'.config('data-import.max_file_kilobytes'),
            ],
        ]);

        $userId = Auth::id();
        abort_unless(is_int($userId) && $this->workbook instanceof TemporaryUploadedFile, 403);
        $batch = ImportBatch::query()->create([
            'created_by' => $userId,
            'kind' => 'reference_configuration',
            'status' => ImportBatchStatus::Uploaded,
        ]);
        $stored = $storage->store($batch->id, $this->workbook);
        ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => $this->workbook->getClientOriginalName(),
            'extension' => 'xlsx',
            'mime_type' => $this->workbook->getMimeType(),
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'encrypted_path' => $stored['path'],
            'status' => 'uploaded',
        ]);
        ParseReferenceConfigurationImport::dispatch($batch->id);
        $this->selectedBatchId = $batch->id;
        $this->reset('workbook', 'confirmImport');
        unset($this->batches, $this->selectedBatch);
        session()->flash('status', '工作簿已加密上传，正在预览、校验和事务预演；此时尚未修改任何配置。');
    }

    public function downloadExample(ReferenceConfigurationTemplateGenerator $templates): BinaryFileResponse
    {
        return response()
            ->download($templates->example(), '基础配置导入-填写示例.xlsx')
            ->deleteFileAfterSend();
    }

    public function downloadErrors(): BinaryFileResponse
    {
        $batch = $this->ownedBatch();
        $rows = $batch->rows()
            ->where('status', 'error')
            ->orderBy('import_file_id')
            ->orderBy('sheet_name')
            ->orderBy('source_row')
            ->get();

        abort_if($rows->isEmpty() && $batch->failure_reason === null, 404, '当前批次没有可下载的错误。');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('基础配置导入错误');
        $sheet->fromArray([
            ['工作表', '源行号', '配置类型', '错误详情', '原始数据'],
        ]);

        $line = 2;
        if ($batch->failure_reason !== null) {
            $sheet->fromArray([[
                '工作簿整体',
                '',
                '文件结构或事务预演',
                $batch->failure_reason,
                '',
            ]], null, "A{$line}");
            $line++;
        }

        foreach ($rows as $row) {
            $sheet->fromArray([[
                $row->sheet_name,
                $row->source_row,
                $row->profile->label(),
                implode("\n", $row->errors ?? []),
                $this->formatRawPayload($row->raw_payload_encrypted ?? []),
            ]], null, "A{$line}");
            $line++;
        }

        $lastLine = max(1, $line - 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:E{$lastLine}");
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle("D2:E{$lastLine}")->getAlignment()->setWrapText(true)->setVertical('top');
        foreach (['A' => 22, 'B' => 12, 'C' => 22, 'D' => 56, 'E' => 72] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $directory = storage_path('app/private');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = "{$directory}/reference-configuration-import-errors-{$batch->id}.xlsx";
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($path, "基础配置导入错误-{$batch->id}.xlsx")
            ->deleteFileAfterSend();
    }

    public function selectBatch(string $batchId): void
    {
        $this->selectedBatchId = $batchId;
        $this->confirmImport = false;
        unset($this->selectedBatch);
    }

    public function reparse(): void
    {
        $batch = $this->ownedBatch();
        ParseReferenceConfigurationImport::dispatch($batch->id);
        $this->confirmImport = false;
        unset($this->batches, $this->selectedBatch);
        session()->flash('status', '批次已重新进入预览、校验和事务预演。');
    }

    public function commitBatch(ReferenceConfigurationImportCommitter $committer): void
    {
        $this->validate([
            'confirmImport' => ['accepted'],
        ], [
            'confirmImport.accepted' => '请先勾选确认，明确同意按预览结果写入全部基础配置。',
        ]);
        $committer->commit($this->ownedBatch(), request()->ip());
        $this->confirmImport = false;
        unset($this->batches, $this->selectedBatch);
        session()->flash('status', '基础配置已按既定顺序在一个事务中完成导入，并写入审计记录。');
    }

    /** @return Collection<int, ImportBatch> */
    #[Computed]
    public function batches(): Collection
    {
        return ImportBatch::query()
            ->where('kind', 'reference_configuration')
            ->withCount('files')
            ->latest()
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function selectedBatch(): ?ImportBatch
    {
        if ($this->selectedBatchId === null) {
            return null;
        }

        return ImportBatch::query()
            ->where('kind', 'reference_configuration')
            ->with(['files', 'rows' => fn ($query) => $query->orderBy('id')->limit(100)])
            ->find($this->selectedBatchId);
    }

    private function ownedBatch(): ImportBatch
    {
        abort_if($this->selectedBatchId === null, 404);

        return ImportBatch::query()
            ->where('kind', 'reference_configuration')
            ->findOrFail($this->selectedBatchId);
    }

    /** @param array<string, mixed> $payload */
    private function formatRawPayload(array $payload): string
    {
        $lines = [];
        foreach ($payload as $field => $value) {
            $lines[] = "{$field}：".$this->displayValue($value);
        }

        return implode("\n", $lines);
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '（空）';
        }

        if (is_bool($value)) {
            return $value ? '是' : '否';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '（无法显示）';
    }
}
