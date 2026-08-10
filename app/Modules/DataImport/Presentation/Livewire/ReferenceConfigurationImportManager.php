<?php

namespace App\Modules\DataImport\Presentation\Livewire;

use App\Modules\DataImport\Application\Services\ImportIssueReportGenerator;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportCommitter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationTemplateGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportOperationMode;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Jobs\ParseReferenceConfigurationImport;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

#[Layout('layouts.app')]
class ReferenceConfigurationImportManager extends Component
{
    use WithFileUploads;

    public ?TemporaryUploadedFile $workbook = null;

    public string $operationMode = 'normal';

    public ?string $operationReason = null;

    public ?string $selectedBatchId = null;

    public bool $confirmImport = false;

    public function render(): View
    {
        return view('livewire.data-imports.reference-configuration-import-manager')->title(__('imports.reference.title'));
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
            'operationMode' => ['required', 'string', 'in:normal,historical_correction'],
            'operationReason' => ['nullable', 'string', 'max:2000', 'required_if:operationMode,historical_correction'],
        ]);

        $userId = Auth::id();
        abort_unless(is_int($userId) && $this->workbook instanceof TemporaryUploadedFile, 403);
        if ($this->operationMode === ImportOperationMode::HistoricalCorrection->value) {
            abort_unless((bool) Auth::user()?->is_super_admin, 403);
        }
        $batch = ImportBatch::query()->create([
            'created_by' => $userId,
            'kind' => 'reference_configuration',
            'operation_mode' => ImportOperationMode::from($this->operationMode),
            'operation_reason' => $this->operationReason,
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
        ParseReferenceConfigurationImport::dispatch($batch->id, app()->getLocale());
        $this->selectedBatchId = $batch->id;
        $this->reset('workbook', 'confirmImport', 'operationReason');
        $this->operationMode = ImportOperationMode::Normal->value;
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: __('imports.toast.reference_uploaded'));
    }

    public function downloadExample(ReferenceConfigurationTemplateGenerator $templates): BinaryFileResponse
    {
        return response()
            ->download($templates->example(), __('imports.reference.files.example'))
            ->deleteFileAfterSend();
    }

    public function downloadErrors(?ImportIssueReportGenerator $reports = null): BinaryFileResponse
    {
        return ($reports ?? app(ImportIssueReportGenerator::class))->download($this->ownedBatch());
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
        ParseReferenceConfigurationImport::dispatch($batch->id, app()->getLocale());
        $this->confirmImport = false;
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: __('imports.toast.reference_reparse'));
    }

    public function commitBatch(ReferenceConfigurationImportCommitter $committer): void
    {
        $this->validate([
            'confirmImport' => ['accepted'],
        ], [
            'confirmImport.accepted' => __('imports.toast.confirm_required'),
        ]);
        $committer->commit($this->ownedBatch(), request()->ip());
        $this->confirmImport = false;
        unset($this->batches, $this->selectedBatch);
        Flux::toast(variant: 'success', text: __('imports.toast.reference_completed'));
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
            ->with(['files', 'issues' => fn ($query) => $query->latest('id')->limit(100), 'rows' => fn ($query) => $query->orderBy('id')->limit(100)])
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
