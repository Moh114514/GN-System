<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\ReferenceConfigurationImportGateway as AgentReferences;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway as ConfigReferences;
use App\Modules\Customer\Application\Contracts\ReferenceConfigurationImportGateway as CustomerReferences;
use App\Modules\DataImport\Application\Exceptions\DryRunRollback;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportOperationMode;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use App\Modules\Settlement\Application\Data\HistoricalCommissionRuleData;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class ReferenceConfigurationImportCommitter
{
    public function __construct(
        private ConfigReferences $config,
        private CustomerReferences $customers,
        private AgentReferences $agents,
        private CommissionConfigurationGateway $commissions,
        private AuditRecorder $audit,
        private ImportIssueRecorder $issues,
        private ImportStageTracker $stages,
    ) {}

    public function dryRun(ImportBatch $batch): void
    {
        $this->stages->update($batch, 'dry_run', 'running');
        try {
            $this->assertValidated($batch);
            DB::transaction(function () use ($batch): void {
                $this->write($batch, null, $batch->created_by);
                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            $summary = $batch->summary ?? [];
            $summary['dry_run_rows'] = $batch->valid_rows;
            $summary['dry_run_completed_at'] = now()->toIso8601String();
            $summary['stages']['dry_run'] = [
                'status' => 'passed',
                'passed_rows' => $batch->valid_rows,
            ];
            $batch->update(['summary' => $summary]);
        } catch (Throwable $exception) {
            $this->issues->record($batch, 'dry_run', 'error', 'dry_run_failed', $exception->getMessage(), null, null, null, [
                'exception' => $exception::class,
            ]);
            $this->issues->markBatchFailure($batch, 'dry_run_failed', $exception->getMessage());
            $this->stages->update($batch, 'dry_run', 'failed', ['issue_count' => 1]);
            throw $exception;
        }
    }

    public function commit(ImportBatch $batch, ?string $ipAddress, ?int $committedBy = null): void
    {
        try {
            $this->assertValidated($batch, true);
            $batch->update(['status' => ImportBatchStatus::Committing]);
            $this->stages->update($batch, 'commit', 'running');
            $actorId = $committedBy ?? $batch->created_by;
            DB::transaction(function () use ($batch, $ipAddress, $actorId): void {
                $this->write($batch, $ipAddress, $actorId);
                $batch->update([
                    'status' => ImportBatchStatus::Completed,
                    'committed_by' => $actorId,
                    'completed_at' => now(),
                    'rollback_expires_at' => null,
                ]);
                $this->audit->record(
                    description: '完成基础配置导入',
                    properties: [
                        'import_batch_id' => $batch->id,
                        'rows' => $batch->valid_rows,
                        'summary' => $batch->summary,
                        'processing_order' => ['基础字典', '政策等级', '费率', '代理商', '等级分配'],
                    ],
                    causerId: $actorId,
                    logName: 'reference-configuration-import',
                    event: 'completed',
                    ipAddress: $ipAddress,
                );
            }, 3);
            $this->stages->update($batch, 'commit', 'passed', ['passed_rows' => $batch->valid_rows]);
        } catch (Throwable $exception) {
            $code = $exception instanceof QueryException ? 'database_constraint_exception' : 'commit_failed';
            $this->issues->record($batch, 'commit', 'error', $code, $exception->getMessage(), null, null, null, [
                'exception' => $exception::class,
            ]);
            $this->issues->markBatchFailure($batch, $code, $exception->getMessage());
            $this->stages->update($batch, 'commit', 'failed', ['issue_count' => 1]);
            $batch->update([
                'status' => ImportBatchStatus::Failed,
            ]);
            throw $exception;
        }
    }

    private function write(ImportBatch $batch, ?string $ipAddress, int $actorId): void
    {
        $institutions = $this->config->upsertInstitutions(
            $this->rows($batch, ImportProfile::Institution),
            $batch->id,
        );
        $this->customers->upsertDirectSalesSources(
            $this->rows($batch, ImportProfile::DirectSalesSource),
            $batch->id,
        );
        $this->agents->upsertAgentTypes(
            $this->rows($batch, ImportProfile::AgentType),
            $batch->id,
        );

        $grades = $this->agents->upsertPolicies(
            $this->rows($batch, ImportProfile::PolicySystem),
            $this->rows($batch, ImportProfile::PolicyGrade),
            $batch->id,
        );

        foreach ($this->rows($batch, ImportProfile::CommissionRule) as $row) {
            $gradeKey = "{$row['policy_system']}|{$row['policy_grade']}";
            $gradeId = $grades[$gradeKey] ?? null;
            $institutionId = $institutions[$row['institution_code']] ?? null;
            if (! is_int($gradeId) || ! is_int($institutionId)) {
                throw new RuntimeException("费率引用不存在：{$gradeKey} / {$row['institution_code']}。");
            }
            $effectiveMonth = CarbonImmutable::parse((string) $row['effective_month']);
            if ($batch->operation_mode === ImportOperationMode::HistoricalCorrection) {
                if (! $effectiveMonth->startOfMonth()->lt(CarbonImmutable::now()->startOfMonth())) {
                    throw new RuntimeException(__('settlements.errors.historical_rate_month_invalid'));
                }
                $this->commissions->importHistoricalCorrectionRule(new HistoricalCommissionRuleData(
                    policyGradeId: $gradeId,
                    institutionId: $institutionId,
                    rateBps: (int) $row['rate_bps'],
                    effectiveMonth: $effectiveMonth,
                    isActive: (bool) $row['is_active'],
                    importBatchId: $batch->id,
                    reason: (string) ($batch->operation_reason ?? ''),
                    actorId: $actorId,
                    ipAddress: $ipAddress,
                ));
            } else {
                $this->commissions->saveRule(
                    policyGradeId: $gradeId,
                    institutionId: $institutionId,
                    rateBps: (int) $row['rate_bps'],
                    effectiveMonth: $effectiveMonth,
                    actorId: $actorId,
                    ipAddress: $ipAddress,
                    isActive: (bool) $row['is_active'],
                );
            }
        }

        $this->agents->upsertAgents(
            $this->rows($batch, ImportProfile::Agent),
            $batch->id,
        );
        $gradeAssignments = $this->rows($batch, ImportProfile::GradeAssignment);
        if ($batch->operation_mode === ImportOperationMode::HistoricalCorrection) {
            $this->agents->importHistoricalGradeAssignments($gradeAssignments, $actorId, $batch->id, $ipAddress);
        } else {
            $this->agents->upsertGradeAssignments($gradeAssignments, $actorId, $batch->id);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(ImportBatch $batch, ImportProfile $profile): array
    {
        return $batch->rows()
            ->where('profile', $profile)
            ->where('status', ImportRowStatus::Valid)
            ->orderBy('id')
            ->get()
            ->map(fn (ImportRow $row): array => $row->normalized_data ?? [])
            ->all();
    }

    private function assertValidated(ImportBatch $batch, bool $requireDryRun = false): void
    {
        if ($batch->kind !== 'reference_configuration' || $batch->status !== ImportBatchStatus::Validated) {
            throw new RuntimeException('只有零错误且已完成事务预演的基础配置批次可以确认导入。');
        }

        if ($batch->operation_mode === ImportOperationMode::HistoricalCorrection && trim((string) $batch->operation_reason) === '') {
            throw new RuntimeException(__('historical_correction.imports.historical_reason_required'));
        }

        if ($requireDryRun && ($batch->summary['stages']['dry_run']['status'] ?? null) !== 'passed') {
            throw new RuntimeException('正式导入前必须完成并通过事务预演。');
        }
    }
}
