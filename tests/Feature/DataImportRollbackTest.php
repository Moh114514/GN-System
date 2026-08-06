<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\ImportBatchRollback;
use App\Modules\DataImport\Application\Services\ImportRowAdjudicator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DataImportRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_is_blocked_when_imported_data_was_modified_after_completion(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $completedAt = now()->subMinutes(10);
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::Completed,
            'completed_at' => $completedAt,
            'rollback_expires_at' => now()->addHours(23),
        ]);

        DB::table('institutions')->insert([
            'code' => 'IMPORTED',
            'name' => 'Imported institution',
            'is_active' => true,
            'import_batch_id' => $batch->id,
            'created_at' => $completedAt->subMinute(),
            'updated_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('institutions:');

        app(ImportBatchRollback::class)->rollback($batch, $user->id);
    }

    public function test_expired_batch_cannot_be_rolled_back(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::Completed,
            'completed_at' => now()->subHours(25),
            'rollback_expires_at' => now()->subHour(),
        ]);

        $this->expectException(RuntimeException::class);

        app(ImportBatchRollback::class)->rollback($batch, $user->id);
    }

    public function test_manual_ignore_resolves_the_last_error_and_is_audited(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
            'total_rows' => 1,
            'error_rows' => 1,
        ]);
        $file = ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => 'summary.csv',
            'extension' => 'csv',
            'mime_type' => 'text/csv',
            'size_bytes' => 10,
            'sha256' => str_repeat('a', 64),
            'encrypted_path' => 'imports/test.enc',
            'status' => 'parsed',
        ]);
        $row = ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'profile' => ImportProfile::SettlementSummary,
            'status' => ImportRowStatus::Error,
            'errors' => ['汇总差异'],
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'import_row_id' => $row->id,
            'stage' => 'normalization',
            'severity' => 'warning',
            'code' => 'optional_field_warning',
            'profile' => ImportProfile::SettlementSummary,
            'source_row' => $row->source_row,
            'message' => 'optional value is missing',
            'is_ignorable' => true,
        ]);

        app(ImportRowAdjudicator::class)->ignore($row, $user->id, '经业务确认不导入该汇总行');

        $this->assertSame(ImportRowStatus::Ignored, $row->fresh()->status);
        $this->assertSame(ImportBatchStatus::Validated, $batch->fresh()->status);
        $this->assertSame('passed', $batch->fresh()->summary['stages']['dry_run']['status']);
        $this->assertNotNull($batch->fresh()->summary['dry_run_completed_at'] ?? null);
        $this->assertDatabaseHas('activity_log', [
            'description' => '人工裁决导入行',
            'causer_id' => $user->id,
        ]);
    }

    public function test_manual_ignore_rejects_non_ignorable_issues(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
        ]);
        $file = ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => 'summary.csv',
            'extension' => 'csv',
            'size_bytes' => 10,
            'sha256' => str_repeat('b', 64),
            'encrypted_path' => 'imports/test.enc',
            'status' => 'parsed',
        ]);
        $row = ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'profile' => ImportProfile::SettlementSummary,
            'status' => ImportRowStatus::Error,
            'errors' => ['summary mismatch'],
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'import_row_id' => $row->id,
            'stage' => 'summary_validation',
            'severity' => 'error',
            'code' => 'summary_mismatch',
            'profile' => ImportProfile::SettlementSummary,
            'source_row' => $row->source_row,
            'message' => 'summary mismatch',
            'is_ignorable' => false,
        ]);

        $this->expectException(RuntimeException::class);
        app(ImportRowAdjudicator::class)->ignore($row, $user->id, 'not allowed');
    }

    public function test_manual_ignore_is_audited_when_the_following_dry_run_fails(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
            'total_rows' => 2,
            'error_rows' => 1,
        ]);
        $file = ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => 'summary.csv',
            'extension' => 'csv',
            'mime_type' => 'text/csv',
            'size_bytes' => 10,
            'sha256' => str_repeat('c', 64),
            'encrypted_path' => 'imports/test.enc',
            'status' => 'parsed',
        ]);
        $row = ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'profile' => ImportProfile::SettlementSummary,
            'status' => ImportRowStatus::Error,
            'errors' => ['summary mismatch'],
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'import_row_id' => $row->id,
            'stage' => 'summary_validation',
            'severity' => 'warning',
            'code' => 'summary_mismatch',
            'profile' => ImportProfile::SettlementSummary,
            'source_row' => $row->source_row,
            'message' => 'summary mismatch',
            'is_ignorable' => true,
        ]);
        ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 3,
            'profile' => ImportProfile::MonthlyDetail,
            'status' => ImportRowStatus::Valid,
            'normalized_data' => [
                'agent_code' => 'UNKNOWN',
                'customer_code' => 'UNKNOWN-0001',
                'customer_name' => 'Test customer',
                'institution' => 'DOD',
                'project_name' => 'Test project',
                'amount_krw' => 100,
                'rate_bps' => 100,
                'commission_krw' => 10,
            ],
        ]);

        try {
            app(ImportRowAdjudicator::class)->ignore($row, $user->id, '业务确认忽略');
            $this->fail('Expected the post-adjudication dry run to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('找不到代理商', $exception->getMessage());
        }

        $this->assertSame(ImportBatchStatus::NeedsReview, $batch->fresh()->status);
        $this->assertDatabaseHas('activity_log', [
            'description' => '人工裁决导入行',
            'causer_id' => $user->id,
        ]);
    }
}
