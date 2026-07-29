<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Jobs\ParseImportBatch;
use App\Modules\DataImport\Presentation\Livewire\ImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DataImportUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($this->admin);
    }

    public function test_import_page_uses_a_non_reserved_upload_action(): void
    {
        $this->get(route('data-imports.index'))
            ->assertOk()
            ->assertSee('wire:submit="stageUploads"', false)
            ->assertDontSee('wire:submit="upload"', false);
    }

    public function test_xlsx_and_csv_files_are_encrypted_and_queued_as_one_batch(): void
    {
        $csv = UploadedFile::fake()->createWithContent('客户档案.csv', "姓名\n验收客户\n");
        $xlsx = UploadedFile::fake()->createWithContent('历史数据.xlsx', 'xlsx-uat-content');

        Livewire::test(ImportManager::class)
            ->set('uploads', [$csv, $xlsx])
            ->call('stageUploads')
            ->assertHasNoErrors()
            ->assertSet('uploads', []);

        $batch = ImportBatch::query()->sole();
        $this->assertSame($this->admin->id, $batch->created_by);
        $this->assertDatabaseCount('import_files', 2);

        foreach (ImportFile::query()->get() as $file) {
            Storage::disk('local')->assertExists($file->encrypted_path);
        }

        Queue::assertPushed(
            ParseImportBatch::class,
            fn (ParseImportBatch $job): bool => $job->batchId === $batch->id,
        );
    }

    public function test_a_file_larger_than_livewires_default_limit_but_within_twenty_megabytes_is_accepted(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            '十三兆数据.csv',
            str_repeat('a', 13 * 1024 * 1024),
        );

        Livewire::test(ImportManager::class)
            ->set('uploads', [$file])
            ->call('stageUploads')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('import_files', 1);
    }

    public function test_invalid_extension_is_rejected_without_creating_a_batch(): void
    {
        $file = UploadedFile::fake()->create('历史数据.pdf', 10, 'application/pdf');

        Livewire::test(ImportManager::class)
            ->set('uploads', [$file])
            ->call('stageUploads')
            ->assertHasErrors(['uploads.0']);

        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_file_over_twenty_megabytes_is_rejected_without_creating_a_batch(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            '超限数据.csv',
            str_repeat('a', ((20 * 1024) + 1) * 1024),
        );

        Livewire::test(ImportManager::class)
            ->set('uploads', [$file])
            ->call('stageUploads')
            ->assertHasErrors();

        $this->assertDatabaseCount('import_batches', 0);
    }

    public function test_more_than_five_files_are_rejected_without_creating_a_batch(): void
    {
        $files = [];
        foreach (range(1, 6) as $index) {
            $files[] = UploadedFile::fake()->create("历史数据{$index}.csv", 10, 'text/csv');
        }

        Livewire::test(ImportManager::class)
            ->set('uploads', $files)
            ->call('stageUploads')
            ->assertHasErrors(['uploads']);

        $this->assertDatabaseCount('import_batches', 0);
    }
}
