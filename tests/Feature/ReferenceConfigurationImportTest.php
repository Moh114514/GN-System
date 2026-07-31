<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportCommitter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportParser;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationTemplateGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Jobs\ParseReferenceConfigurationImport;
use App\Modules\DataImport\Presentation\Livewire\ReferenceConfigurationImportManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ReferenceConfigurationImportTest extends TestCase
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

    public function test_page_is_protected_linked_from_configuration_and_downloads_complete_example(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('reference-configuration-imports.index'))->assertForbidden();

        $this->actingAs($this->admin)->get(route('configuration.index'))
            ->assertOk()
            ->assertSee('基础配置导入')
            ->assertSee('href="'.route('reference-configuration-imports.index').'"', false);

        $this->get(route('reference-configuration-imports.index'))
            ->assertOk()
            ->assertSee('返回配置中心')
            ->assertSee('上传只进行预览和校验')
            ->assertSee('基础字典 → 政策等级 → 费率 → 代理商 → 等级分配')
            ->assertSee('wire:submit="stageWorkbook"', false);

        $path = app(ReferenceConfigurationTemplateGenerator::class)->example();
        $workbook = IOFactory::load($path);
        $this->assertSame(
            ['填写说明', ...array_keys(ReferenceConfigurationTemplateGenerator::HEADERS)],
            $workbook->getSheetNames(),
        );
        $this->assertSame('每个代理商仅支持一个 legacy_code；当前系统不支持代理商多别名。', $workbook->getSheet(0)->getCell('B9')->getValue());
        $workbook->disconnectWorksheets();
        unlink($path);

        Livewire::test(ReferenceConfigurationImportManager::class)
            ->call('downloadExample')
            ->assertFileDownloaded('基础配置导入-填写示例.xlsx');
    }

    public function test_upload_only_creates_encrypted_preview_batch_and_requires_confirmation(): void
    {
        $file = $this->exampleUpload();

        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $file)
            ->call('stageWorkbook')
            ->assertHasNoErrors()
            ->assertSet('workbook', null);

        $batch = ImportBatch::query()->sole();
        $this->assertSame('reference_configuration', $batch->kind);
        Storage::disk('local')->assertExists($batch->files()->sole()->encrypted_path);
        Queue::assertPushed(
            ParseReferenceConfigurationImport::class,
            fn (ParseReferenceConfigurationImport $job): bool => $job->batchId === $batch->id,
        );
        $this->assertDatabaseCount('institutions', 0);
        $this->assertDatabaseCount('agents', 0);

        app(ReferenceConfigurationImportParser::class)->parse($batch);
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('selectedBatchId', $batch->id)
            ->assertSee('wire:click="commitBatch"', false)
            ->assertDontSee('wire:click="commit"', false)
            ->call('commitBatch')
            ->assertHasErrors(['confirmImport']);
    }

    public function test_valid_workbook_is_previewed_dry_run_and_committed_atomically_in_dependency_order(): void
    {
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $this->exampleUpload())
            ->call('stageWorkbook');

        $batch = ImportBatch::query()->with('files')->sole();
        app(ReferenceConfigurationImportParser::class)->parse($batch);
        $batch->refresh();
        $this->assertSame(ImportBatchStatus::Validated, $batch->status);
        $this->assertSame(8, $batch->valid_rows);
        $this->assertSame(0, $batch->error_rows);

        app(ReferenceConfigurationImportCommitter::class)->dryRun($batch);
        $this->assertDatabaseCount('institutions', 0);
        $this->assertDatabaseCount('policy_grades', 0);
        $this->assertDatabaseCount('agents', 0);
        $this->assertNotNull($batch->fresh()->summary['dry_run_completed_at'] ?? null);

        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('selectedBatchId', $batch->id)
            ->set('confirmImport', true)
            ->call('commitBatch')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('agent_type_codes', ['code' => 'UAT', 'name' => 'UAT 代理']);
        $this->assertDatabaseHas('institutions', ['code' => 'UAT-HOSP', 'name' => 'UAT 示例机构']);
        $this->assertDatabaseHas('institution_aliases', ['alias' => '示例医院']);
        $this->assertDatabaseHas('direct_sales_sources', ['code' => 'UAT', 'name' => 'UAT 直销']);
        $this->assertDatabaseHas('policy_systems', ['name' => 'UAT 示例政策']);
        $this->assertDatabaseHas('policy_grades', ['name' => 'UAT 银级', 'monthly_threshold_krw' => 1000000]);
        $this->assertDatabaseHas('commission_rules', ['rate_bps' => 1200, 'is_active' => true]);
        $this->assertDatabaseHas('agents', ['code' => 'UATP5-UAT', 'name' => 'UAT 示例代理商']);
        $this->assertDatabaseCount('agent_grade_assignments', 1);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reference-configuration-import',
            'description' => '完成基础配置导入',
            'causer_id' => $this->admin->id,
        ]);
        $this->assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_relationship_errors_block_confirmation_and_identify_the_source_row(): void
    {
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $this->exampleUpload())
            ->call('stageWorkbook');
        $batch = ImportBatch::query()->with('files')->sole();

        $path = storage_path('app/private/test-invalid-reference.xlsx');
        $contents = app(EncryptedImportStorage::class)
            ->decrypt($batch->files->sole()->encrypted_path);
        file_put_contents($path, $contents);
        $workbook = IOFactory::load($path);
        $workbook->getSheetByName('机构费率规则')?->setCellValue('C2', 'MISSING');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $replacement = UploadedFile::fake()->createWithContent('错误配置.xlsx', file_get_contents($path) ?: '');
        unlink($path);

        ImportBatch::query()->delete();
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $replacement)
            ->call('stageWorkbook');
        $invalidBatch = ImportBatch::query()->with('files')->sole();
        app(ReferenceConfigurationImportParser::class)->parse($invalidBatch);

        $this->assertSame(ImportBatchStatus::NeedsReview, $invalidBatch->fresh()->status);
        $this->assertDatabaseHas('import_rows', [
            'import_batch_id' => $invalidBatch->id,
            'sheet_name' => '机构费率规则',
            'source_row' => 2,
            'status' => 'error',
        ]);
        $this->assertStringContainsString(
            '机构代码“MISSING”不存在',
            implode('；', $invalidBatch->rows()->where('sheet_name', '机构费率规则')->sole()->errors ?? []),
        );

        $component = Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('selectedBatchId', $invalidBatch->id)
            ->assertSee('发现 1 行错误，当前批次不能写入')
            ->assertSee('机构费率规则 #2')
            ->assertSee('机构代码“MISSING”不存在')
            ->call('downloadErrors')
            ->assertFileDownloaded("基础配置导入错误-{$invalidBatch->id}.xlsx");

        $manager = app(ReferenceConfigurationImportManager::class);
        $manager->selectedBatchId = $invalidBatch->id;
        $response = $manager->downloadErrors();
        $reportPath = $response->getFile()->getPathname();
        $report = IOFactory::load($reportPath);
        $sheet = $report->getActiveSheet();
        $this->assertSame('工作表', $sheet->getCell('A1')->getValue());
        $this->assertSame('机构费率规则', $sheet->getCell('A2')->getValue());
        $this->assertSame(2, $sheet->getCell('B2')->getValue());
        $this->assertStringContainsString('机构代码“MISSING”不存在', (string) $sheet->getCell('D2')->getValue());
        $this->assertStringContainsString('机构代码：MISSING', (string) $sheet->getCell('E2')->getValue());
        $report->disconnectWorksheets();
        unlink($reportPath);
    }

    public function test_error_report_includes_batch_level_workbook_failures(): void
    {
        $batch = ImportBatch::query()->create([
            'created_by' => $this->admin->id,
            'kind' => 'reference_configuration',
            'status' => ImportBatchStatus::Failed,
            'failure_reason' => '缺少工作表：代理商类型。请使用下载示例保留全部八个工作表。',
        ]);

        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('selectedBatchId', $batch->id)
            ->assertSee('工作簿处理失败')
            ->assertSee('缺少工作表：代理商类型')
            ->call('downloadErrors')
            ->assertFileDownloaded("基础配置导入错误-{$batch->id}.xlsx");
    }

    public function test_field_validation_errors_include_field_value_and_allowed_format(): void
    {
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $this->exampleUpload())
            ->call('stageWorkbook');
        $batch = ImportBatch::query()->with('files')->sole();

        $path = storage_path('app/private/test-invalid-field.xlsx');
        $contents = app(EncryptedImportStorage::class)
            ->decrypt($batch->files->sole()->encrypted_path);
        file_put_contents($path, $contents);
        $workbook = IOFactory::load($path);
        $workbook->getSheetByName('机构及机构别名')?->setCellValue('A2', '错误 代码');
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $replacement = UploadedFile::fake()->createWithContent('字段错误.xlsx', file_get_contents($path) ?: '');
        unlink($path);

        ImportBatch::query()->delete();
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $replacement)
            ->call('stageWorkbook');
        $invalidBatch = ImportBatch::query()->with('files')->sole();
        app(ReferenceConfigurationImportParser::class)->parse($invalidBatch);

        $error = implode(
            '；',
            $invalidBatch->rows()->where('sheet_name', '机构及机构别名')->sole()->errors ?? [],
        );
        $this->assertStringContainsString('机构代码“错误 代码”格式不正确', $error);
        $this->assertStringContainsString('1-32 位大写字母、数字、下划线或连字符', $error);
    }

    private function exampleUpload(): UploadedFile
    {
        $path = app(ReferenceConfigurationTemplateGenerator::class)->example();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('基础配置.xlsx', $contents === false ? '' : $contents);
    }
}
