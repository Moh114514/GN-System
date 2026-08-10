<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Agent\Infrastructure\Models\Agent;
use App\Modules\Agent\Infrastructure\Models\AgentTypeCode;
use App\Modules\DataImport\Application\Services\ImportBatchCommitter;
use App\Modules\DataImport\Application\Services\ImportBatchRollback;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use App\Modules\DataImport\Presentation\Livewire\ImportManager;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

        $this->actingAs($user);
        $statusChanges = [];
        ImportBatch::updated(function (ImportBatch $updatedBatch) use ($batch, &$statusChanges): void {
            if ($updatedBatch->id === $batch->id && $updatedBatch->wasChanged('status')) {
                $statusChanges[] = $updatedBatch->status;
            }
        });

        Livewire::test(ImportManager::class)
            ->set('selectedBatchId', $batch->id)
            ->assertSee('wire:click="commitBatch"', false)
            ->assertDontSee('wire:click="commit"', false)
            ->call('commitBatch')
            ->assertHasNoErrors();

        $this->assertSame(
            [ImportBatchStatus::Committing, ImportBatchStatus::Completed],
            $statusChanges,
        );
        $this->assertSame(ImportBatchStatus::Completed, $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
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
        $batch->update(['summary' => ['stages' => ['dry_run' => ['status' => 'passed']]]]);

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

    public function test_formal_commit_materializes_historical_settlement_items(): void
    {
        [, $batch, $file] = $this->batch();
        $batch->update(['total_rows' => 3, 'valid_rows' => 3]);
        $this->agentRow($batch, $file);
        $this->detailRow($batch, $file, 'SZ-JG', scheduledOn: '2026-07-15');
        $this->summaryRow($batch, $file);

        $committer = app(ImportBatchCommitter::class);
        $committer->dryRun($batch);
        $committer->commit($batch);

        $settlement = Settlement::query()->where('import_batch_id', $batch->id)->firstOrFail();
        $this->assertDatabaseHas('settlement_items', [
            'settlement_id' => $settlement->id,
            'import_batch_id' => $batch->id,
            'commission_krw' => 1350000,
        ]);
        $this->assertSame(1, $settlement->fresh()->item_count);
    }

    public function test_commit_rejects_a_validated_batch_without_a_passed_dry_run(): void
    {
        [, $batch] = $this->batch();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('事务预演');

        app(ImportBatchCommitter::class)->commit($batch);
    }

    public function test_historical_settlement_import_is_not_applicable_to_generation(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $type = AgentTypeCode::query()->where('code', 'JG')->firstOrFail();
        $agent = Agent::query()->create([
            'agent_type_code_id' => $type->id,
            'code' => 'SZ-JG',
            'name' => '神州国际旅行社',
        ]);

        $importBatchId = (string) Str::uuid();
        $settlementId = app(SettlementImportGateway::class)->upsertSettlement(new SettlementImportData(
            agentId: $agent->id,
            periodStart: CarbonImmutable::parse('2026-07-01'),
            periodEnd: CarbonImmutable::parse('2026-07-31'),
            settledOn: CarbonImmutable::parse('2026-08-05'),
            exchangeRateKrwPerCny: '224',
            totalConsumptionKrw: 0,
            totalCommissionKrw: 0,
            payoutAmountCnyFen: 0,
            status: 'reconciled',
            importBatchId: $importBatchId,
        ));

        $this->assertDatabaseHas('settlements', [
            'id' => $settlementId,
            'generation_status' => 'not_applicable',
            'generated_at' => null,
            'item_count' => 0,
            'import_batch_id' => $importBatchId,
        ]);
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
        ?string $scheduledOn = '2026-07-15',
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
                'scheduled_on' => $scheduledOn,
            ],
        ]);
    }

    private function summaryRow(ImportBatch $batch, ImportFile $file): void
    {
        ImportRow::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id,
            'source_row' => 4,
            'profile' => ImportProfile::SettlementSummary,
            'status' => ImportRowStatus::Valid,
            'normalized_data' => [
                'agent_code' => 'SZ-JG',
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'settled_on' => '2026-08-01',
                'exchange_rate_krw_per_cny' => '190',
                'consumption_krw' => 12000000,
                'commission_krw' => 1350000,
                'payout_cny_fen' => 7105263,
                'status' => 'reconciled',
            ],
        ]);
    }
}
