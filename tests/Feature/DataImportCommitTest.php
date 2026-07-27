<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\ImportBatchCommitter;
use App\Modules\DataImport\Application\Services\ImportBatchRollback;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DataImportCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_batch_commits_atomically_and_can_be_rolled_back(): void
    {
        [$user, $batch, $file] = $this->batch();
        $this->agentRow($batch, $file);
        $this->detailRow($batch, $file, 'SZ-JG');

        $committer = app(ImportBatchCommitter::class);
        $committer->dryRun($batch);

        $this->assertSame(ImportBatchStatus::Validated, $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->summary['dry_run_rows']);
        $this->assertDatabaseCount('agents', 0);
        $this->assertDatabaseCount('orders', 0);

        $committer->commit($batch->fresh());

        $this->assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        $this->assertDatabaseHas('agents', ['code' => 'SZ-JG']);
        $this->assertDatabaseHas('customers', ['code' => 'SZ-JG-0001']);
        $this->assertDatabaseHas('orders', ['amount_krw' => 12000000, 'channel' => 'agent']);
        $this->assertDatabaseHas('order_commissions', ['amount_krw' => 1350000, 'rate_bps' => 1125]);

        app(ImportBatchRollback::class)->rollback($batch->fresh(), $user->id);

        $this->assertSame(ImportBatchStatus::RolledBack, $batch->fresh()->status);
        $this->assertDatabaseMissing('agents', ['code' => 'SZ-JG']);
        $this->assertDatabaseMissing('customers', ['code' => 'SZ-JG-0001']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_commissions', 0);
    }

    public function test_failure_in_later_row_leaves_no_partial_business_data(): void
    {
        [, $batch, $file] = $this->batch();
        $this->agentRow($batch, $file);
        $this->detailRow($batch, $file, 'SZ-JG');
        $this->detailRow($batch, $file, 'UNKNOWN-JG', 'UNKNOWN-JG-0001', 4);

        try {
            app(ImportBatchCommitter::class)->commit($batch);
            $this->fail('Expected the invalid second detail row to abort the batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('UNKNOWN-JG', $exception->getMessage());
        }

        $this->assertSame(ImportBatchStatus::Failed, $batch->fresh()->status);
        $this->assertDatabaseCount('agents', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_commissions', 0);
    }

    /** @return array{User, ImportBatch, ImportFile} */
    private function batch(): array
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::Validated,
            'total_rows' => 2,
            'valid_rows' => 2,
        ]);
        $file = ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => 'fixture.csv',
            'extension' => 'csv',
            'mime_type' => 'text/csv',
            'size_bytes' => 10,
            'sha256' => str_repeat('b', 64),
            'encrypted_path' => 'imports/fixture.enc',
            'status' => 'parsed',
        ]);

        return [$user, $batch, $file];
    }

    private function agentRow(ImportBatch $batch, ImportFile $file): void
    {
        ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 2,
            'profile' => ImportProfile::AgentArchive,
            'status' => ImportRowStatus::Valid,
            'normalized_data' => [
                'source_code' => 'SZ-JG',
                'name' => '神州国际旅行社',
                'business_role' => '旅行社',
                'cooperation_status' => 'active',
            ],
        ]);
    }

    private function detailRow(
        ImportBatch $batch,
        ImportFile $file,
        string $agentCode,
        string $customerCode = 'SZ-JG-0001',
        int $sourceRow = 3,
    ): void {
        ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => $sourceRow,
            'profile' => ImportProfile::MonthlyDetail,
            'status' => ImportRowStatus::Valid,
            'normalized_data' => [
                'agent_code' => $agentCode,
                'customer_code' => $customerCode,
                'customer_name' => '测试客户',
                'institution' => 'DOD',
                'project_name' => '历史项目',
                'amount_krw' => 12000000,
                'rate_bps' => 1125,
                'commission_krw' => 1350000,
            ],
        ]);
    }
}
