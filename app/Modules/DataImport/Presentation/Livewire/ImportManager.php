<?php

namespace App\Modules\DataImport\Presentation\Livewire;

use App\Modules\DataImport\Application\Services\ImportBatchCommitter;
use App\Modules\DataImport\Application\Services\ImportBatchRollback;
use App\Modules\DataImport\Application\Services\ImportIssueReportGenerator;
use App\Modules\DataImport\Application\Services\ImportReferenceManager;
use App\Modules\DataImport\Application\Services\ImportReferenceReadiness;
use App\Modules\DataImport\Application\Services\ImportRowAdjudicator;
use App\Modules\DataImport\Application\Services\ImportTemplateGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Jobs\ParseImportBatch;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Layout('layouts.app')]
#[Title('历史数据导入')]
class ImportManager extends Component
{
    use WithFileUploads;

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    public ?string $selectedBatchId = null;

    public string $institutionCode = '';

    public string $institutionName = '';

    public string $institutionAliases = '';

    public string $directSourceCode = '';

    public string $directSourceName = '';

    /** @var array<int, string> */
    public array $ignoreReasons = [];

    public function render(): View
    {
        return view('livewire.data-imports.import-manager');
    }

    public function stageUploads(
        EncryptedImportStorage $storage,
        ImportReferenceReadiness $readiness,
    ): void {
        $this->validate([
            'uploads' => ['required', 'array', 'min:1', 'max:5'],
            'uploads.*' => [
                'file',
                'mimes:'.implode(',', config('data-import.allowed_extensions')),
                'max:'.config('data-import.max_file_kilobytes'),
            ],
        ]);

        $referenceState = $readiness->inspect();
        if (! $referenceState['ready']) {
            $this->addError(
                'uploads',
                '导入基础数据未就绪：'.implode('、', $referenceState['issues']).'。',
            );

            return;
        }

        $userId = Auth::id();
        abort_unless(is_int($userId), 403);

        $batch = ImportBatch::query()->create([
            'created_by' => $userId,
            'kind' => 'historical',
            'status' => ImportBatchStatus::Uploaded,
        ]);

        foreach ($this->uploads as $upload) {
            $stored = $storage->store($batch->id, $upload);
            ImportFile::query()->create([
                'import_batch_id' => $batch->id,
                'original_name' => $upload->getClientOriginalName(),
                'extension' => strtolower($upload->getClientOriginalExtension()),
                'mime_type' => $upload->getMimeType(),
                'size_bytes' => $stored['size'],
                'sha256' => $stored['sha256'],
                'encrypted_path' => $stored['path'],
                'status' => 'uploaded',
            ]);
        }

        ParseImportBatch::dispatch($batch->id);
        $this->selectedBatchId = $batch->id;
        $this->reset('uploads');
        unset($this->batches, $this->selectedBatch);

        Flux::toast(variant: 'success', text: '文件已上传，系统正在检查数据。');
    }

    public function downloadStructureExample(ImportTemplateGenerator $templates): BinaryFileResponse
    {
        return response()
            ->download($templates->structureExample(), '历史数据导入-结构示例.xlsx')
            ->deleteFileAfterSend();
    }

    public function downloadImportableSimulation(ImportTemplateGenerator $templates): BinaryFileResponse
    {
        return response()
            ->download($templates->importableSimulation(), '历史数据导入-可导入模拟数据.xlsx')
            ->deleteFileAfterSend();
    }

    public function selectBatch(string $batchId): void
    {
        $this->selectedBatchId = $batchId;
        unset($this->selectedBatch);
    }

    public function reparse(): void
    {
        $batch = $this->ownedBatch();
        ParseImportBatch::dispatch($batch->id);
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: '已重新检查本批次数据。');
    }

    public function saveInstitution(ImportReferenceManager $references): void
    {
        $validated = $this->validate([
            'institutionCode' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/'],
            'institutionName' => ['required', 'string', 'max:160'],
            'institutionAliases' => ['nullable', 'string', 'max:1000'],
        ]);

        $references->upsertInstitution(
            $validated['institutionCode'],
            $validated['institutionName'],
            preg_split('/[,，\\r\\n]+/u', $validated['institutionAliases']) ?: [],
        );
        $this->reset('institutionCode', 'institutionName', 'institutionAliases');
        Flux::toast(variant: 'success', text: '机构信息已保存，请重新检查本批次。');
    }

    public function saveDirectSource(ImportReferenceManager $references): void
    {
        $validated = $this->validate([
            'directSourceCode' => ['required', 'string', 'regex:/^[A-Z0-9]{2,6}$/'],
            'directSourceName' => ['required', 'string', 'max:120'],
        ]);

        $references->upsertDirectSalesSource($validated['directSourceCode'], $validated['directSourceName']);
        $this->reset('directSourceCode', 'directSourceName');
        Flux::toast(variant: 'success', text: '直销来源代码已保存。');
    }

    public function ignoreRow(int $rowId, ImportRowAdjudicator $adjudicator): void
    {
        $batch = $this->ownedBatch();
        $row = $batch->rows()->findOrFail($rowId);
        $reason = trim($this->ignoreReasons[$rowId] ?? '');
        if ($reason === '') {
            $this->addError("ignoreReasons.{$rowId}", '请填写忽略原因。');

            return;
        }

        $userId = Auth::id();
        abort_unless(is_int($userId), 403);
        $adjudicator->ignore($row, $userId, $reason);
        unset($this->ignoreReasons[$rowId], $this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: '该行已标记为忽略，并记录操作日志。');
    }

    public function commitBatch(ImportBatchCommitter $committer): void
    {
        $batch = $this->ownedBatch();
        $committer->commit($batch);
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: '导入已完成，24 小时内可撤销。');
    }

    public function rollback(ImportBatchRollback $rollback): void
    {
        $userId = Auth::id();
        abort_unless(is_int($userId), 403);
        $rollback->rollback($this->ownedBatch(), $userId);
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: '本次导入已撤销。');
    }

    public function downloadErrors(?ImportIssueReportGenerator $reports = null): BinaryFileResponse
    {
        return ($reports ?? app(ImportIssueReportGenerator::class))->download($this->ownedBatch());
    }

    /** @return Collection<int, ImportBatch> */
    #[Computed]
    public function batches(): Collection
    {
        return ImportBatch::query()
            ->where('kind', 'historical')
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
            ->where('kind', 'historical')
            ->with(['files', 'issues' => fn ($query) => $query->latest('id')->limit(100), 'rows' => fn ($query) => $query->orderBy('id')->limit(50)])
            ->find($this->selectedBatchId);
    }

    /**
     * @return array{
     *     ready: bool,
     *     issues: array<int, string>,
     *     agent_types: array<int, array{code: string, name: string}>,
     *     institutions: array<int, array{code: string, name: string}>,
     *     direct_sales_sources: array<int, array{code: string, name: string}>
     * }
     */
    #[Computed]
    public function referenceReadiness(): array
    {
        return app(ImportReferenceReadiness::class)->inspect();
    }

    private function ownedBatch(): ImportBatch
    {
        abort_if($this->selectedBatchId === null, 404);

        return ImportBatch::query()
            ->where('kind', 'historical')
            ->findOrFail($this->selectedBatchId);
    }
}
