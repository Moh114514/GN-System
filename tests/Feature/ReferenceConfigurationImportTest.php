<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\PolicyGrade;
use App\Modules\Agent\Infrastructure\Models\PolicySystem;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\DataImport\Application\Services\ImportIssueMessagePresenter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportCommitter;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationImportParser;
use App\Modules\DataImport\Application\Services\ReferenceConfigurationTemplateGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportOperationMode;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Jobs\ParseReferenceConfigurationImport;
use App\Modules\DataImport\Presentation\Livewire\ReferenceConfigurationImportManager;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Data\HistoricalCommissionRuleData;
use App\Modules\Settlement\Infrastructure\Models\CommissionRule;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
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
            ->assertSee('数据导入与迁移')
            ->assertSee('href="'.route('configuration.data-maintenance').'"', false);

        $this->get(route('configuration.data-maintenance'))
            ->assertOk()
            ->assertSee('基础配置导入')
            ->assertSee('历史数据迁移')
            ->assertSee('href="'.route('reference-configuration-imports.index').'"', false)
            ->assertSee('href="'.route('data-imports.index').'"', false);

        $this->get(route('reference-configuration-imports.index'))
            ->assertOk()
            ->assertSee('返回数据导入与迁移')
            ->assertSee('href="'.route('configuration.data-maintenance').'"', false)
            ->assertSee('上传后先预览和检查')
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

    public function test_korean_admin_sees_localized_reference_import_copy(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($user)
            ->get(route('reference-configuration-imports.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('기준 설정 가져오기')
            ->assertSee('업로드 및 미리보기 생성')
            ->assertDontSee('基础配置导入');
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
        $this->assertSame(7, $batch->valid_rows);
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
        $this->assertDatabaseHas('policy_systems', ['name' => 'UAT 示例政策']);
        $this->assertDatabaseHas('policy_grades', ['name' => 'UAT 银级', 'monthly_threshold_krw' => 1000000]);
        $this->assertDatabaseHas('commission_rules', ['rate_bps' => 1200, 'is_active' => true]);
        $this->assertDatabaseHas('agents', ['code' => 'UATP5-UAT', 'name' => 'UAT 示例代理商']);
        $this->assertDatabaseCount('agent_grade_assignments', 1);
        $this->assertDatabaseHas('agent_grade_assignments', [
            'effective_month' => now()->startOfMonth()->toDateString(),
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reference-configuration-import',
            'description' => '完成基础配置导入',
            'causer_id' => $this->admin->id,
        ]);
        $this->assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
    }

    public function test_commit_rejects_a_validated_batch_without_a_passed_dry_run(): void
    {
        $batch = ImportBatch::query()->create([
            'created_by' => $this->admin->id,
            'kind' => 'reference_configuration',
            'status' => ImportBatchStatus::Validated,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('事务预演');

        app(ReferenceConfigurationImportCommitter::class)->commit($batch, null);
    }

    public function test_commit_records_the_actual_committer_separately_from_uploader(): void
    {
        $committer = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $this->admin->id,
            'kind' => 'reference_configuration',
            'operation_mode' => ImportOperationMode::Normal,
            'status' => ImportBatchStatus::Validated,
        ]);

        $service = app(ReferenceConfigurationImportCommitter::class);
        $service->dryRun($batch);
        $service->commit($batch->fresh(), null, $committer->id);

        $this->assertDatabaseHas('import_batches', [
            'id' => $batch->id,
            'created_by' => $this->admin->id,
            'committed_by' => $committer->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'reference-configuration-import',
            'event' => 'completed',
            'causer_id' => $committer->id,
        ]);
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
        $this->assertSame(0, $invalidBatch->fresh()->summary['stages']['field_validation']['error_rows']);
        $this->assertSame('failed', $invalidBatch->fresh()->summary['stages']['relation_validation']['status']);
        $this->assertSame('passed', $invalidBatch->fresh()->summary['stages']['normalization']['status']);
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
            ->assertFileDownloaded("导入问题报告-{$invalidBatch->id}.xlsx");

        $manager = app(ReferenceConfigurationImportManager::class);
        $manager->selectedBatchId = $invalidBatch->id;
        $response = $manager->downloadErrors();
        $reportPath = $response->getFile()->getPathname();
        $report = IOFactory::load($reportPath);
        $sheet = $report->getActiveSheet();
        $this->assertSame('阶段', $sheet->getCell('A1')->getValue());
        $this->assertSame('关联校验', $sheet->getCell('A2')->getValue());
        $this->assertSame('错误', $sheet->getCell('B2')->getValue());
        $this->assertSame('机构费率规则', $sheet->getCell('E2')->getValue());
        $this->assertStringContainsString('机构代码“MISSING”不存在', (string) $sheet->getCell('N2')->getValue());
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
            ->assertSee(__('imports.errors.batch_failure'))
            ->assertDontSee('缺少工作表：代理商类型')
            ->call('downloadErrors')
            ->assertFileDownloaded("导入问题报告-{$batch->id}.xlsx");
    }

    public function test_parser_stores_a_structured_batch_failure_and_presents_it_in_korean(): void
    {
        $batch = ImportBatch::query()->create([
            'created_by' => $this->admin->id,
            'kind' => 'reference_configuration',
            'status' => ImportBatchStatus::Uploaded,
        ]);

        try {
            app(ReferenceConfigurationImportParser::class)->parse($batch);
            $this->fail('The parser should fail when the workbook file is missing.');
        } catch (\Throwable) {
            $batch->refresh();
        }

        $this->assertSame('imports.errors.file_detection_failed', $batch->failure_reason_key);
        $this->assertNull($batch->failure_reason_parameters);

        $previousLocale = app()->getLocale();
        app()->setLocale('ko_KR');
        try {
            $this->assertSame(__('imports.errors.file_detection_failed'), app(ImportIssueMessagePresenter::class)->presentBatch($batch));
        } finally {
            app()->setLocale($previousLocale);
        }
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

    public function test_normal_mode_rejects_historical_commission_month_during_parser_validation(): void
    {
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('workbook', $this->exampleUploadWithCommissionMonth(now()->subMonths(2)->startOfMonth()->format('Y-m-d')))
            ->call('stageWorkbook');

        $batch = ImportBatch::query()->with('files')->sole();
        app(ReferenceConfigurationImportParser::class)->parse($batch);

        $this->assertSame(ImportOperationMode::Normal, $batch->fresh()->operation_mode);
        $this->assertSame(ImportBatchStatus::NeedsReview, $batch->fresh()->status);
        $this->assertDatabaseHas('import_issues', ['import_batch_id' => $batch->id, 'stage' => 'business_validation']);
    }

    public function test_historical_mode_persists_and_commits_a_past_commission_month(): void
    {
        Livewire::test(ReferenceConfigurationImportManager::class)
            ->set('operationMode', ImportOperationMode::HistoricalCorrection->value)
            ->set('operationReason', '历史费率补录测试')
            ->set('workbook', $this->exampleUploadWithCommissionMonth('2026-04-01'))
            ->call('stageWorkbook');

        $batch = ImportBatch::query()->with('files')->sole();
        $this->assertSame(ImportOperationMode::HistoricalCorrection, $batch->operation_mode);
        app(ReferenceConfigurationImportParser::class)->parse($batch);
        $batch->refresh();
        $this->assertSame(ImportBatchStatus::Validated, $batch->status);
        app(ReferenceConfigurationImportCommitter::class)->dryRun($batch);
        app(ReferenceConfigurationImportCommitter::class)->commit($batch, null);

        $this->assertDatabaseHas('commission_rules', ['effective_month' => '2026-04-01', 'rate_bps' => 1200, 'import_batch_id' => $batch->id]);
    }

    public function test_historical_commission_rule_is_idempotent_and_conflicts_are_rejected(): void
    {
        $system = PolicySystem::query()->create(['name' => '测试政策', 'is_active' => true]);
        $grade = PolicyGrade::query()->create([
            'policy_system_id' => $system->id,
            'name' => '测试等级',
            'monthly_threshold_krw' => 0,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $institution = Institution::query()->create(['code' => 'TEST', 'name' => '测试机构', 'is_active' => true]);
        $rule = CommissionRule::query()->create([
            'policy_grade_id' => $grade->id,
            'institution_id' => $institution->id,
            'rate_bps' => 1200,
            'effective_month' => '2026-04-01',
            'is_active' => true,
        ]);
        $data = new HistoricalCommissionRuleData($grade->id, $institution->id, 1200, CarbonImmutable::parse('2026-04-01'), true, 'batch-id', '测试', $this->admin->id, null);
        app(CommissionConfigurationGateway::class)->importHistoricalCorrectionRule($data);
        $this->assertSame(1, CommissionRule::query()->where('id', $rule->id)->count());

        $this->expectException(DomainException::class);
        app(CommissionConfigurationGateway::class)
            ->importHistoricalCorrectionRule(new HistoricalCommissionRuleData($grade->id, $institution->id, 1300, CarbonImmutable::parse('2026-04-01'), true, 'batch-id', '测试', $this->admin->id, null));
    }

    private function exampleUpload(): UploadedFile
    {
        $path = app(ReferenceConfigurationTemplateGenerator::class)->example();
        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('基础配置.xlsx', $contents === false ? '' : $contents);
    }

    private function exampleUploadWithCommissionMonth(string $month): UploadedFile
    {
        $path = app(ReferenceConfigurationTemplateGenerator::class)->example();
        $workbook = IOFactory::load($path);
        $commissionSheet = $workbook->getSheetByName(array_keys(ReferenceConfigurationTemplateGenerator::HEADERS)[4]);
        $commissionSheet?->setCellValue('E2', $month);
        $agentSheet = $workbook->getSheetByName(array_keys(ReferenceConfigurationTemplateGenerator::HEADERS)[5]);
        $agentSheet?->setCellValue('G2', '2026-01-01');
        $gradeSheet = $workbook->getSheetByName(array_keys(ReferenceConfigurationTemplateGenerator::HEADERS)[6]);
        $gradeSheet?->setCellValue('D2', $month);
        (new Xlsx($workbook))->save($path);
        $workbook->disconnectWorksheets();
        $contents = file_get_contents($path) ?: '';
        unlink($path);

        return UploadedFile::fake()->createWithContent('历史费率.xlsx', $contents);
    }
}
