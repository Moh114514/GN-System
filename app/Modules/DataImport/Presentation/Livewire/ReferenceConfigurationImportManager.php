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

    public function commit(ReferenceConfigurationImportCommitter $committer): void
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
}
