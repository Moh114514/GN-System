<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\ReferenceConfigurationImportGateway as AgentReferences;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway as ConfigReferences;
use App\Modules\Customer\Application\Contracts\ReferenceConfigurationImportGateway as CustomerReferences;
use App\Modules\DataImport\Application\Exceptions\DryRunRollback;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use App\Modules\Settlement\Application\Contracts\CommissionConfigurationGateway;
use Carbon\CarbonImmutable;
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
    ) {}

    public function dryRun(ImportBatch $batch): void
    {
        $this->assertValidated($batch);

        try {
            DB::transaction(function () use ($batch): void {
                $this->write($batch, null);
                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            $summary = $batch->summary ?? [];
            $summary['dry_run_rows'] = $batch->valid_rows;
            $summary['dry_run_completed_at'] = now()->toIso8601String();
            $batch->update(['summary' => $summary]);
        }
    }

    public function commit(ImportBatch $batch, ?string $ipAddress): void
    {
        $this->assertValidated($batch);
        $batch->update(['status' => ImportBatchStatus::Committing]);

        try {
            DB::transaction(function () use ($batch, $ipAddress): void {
                $this->write($batch, $ipAddress);
                $batch->update([
                    'status' => ImportBatchStatus::Completed,
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
                    causerId: $batch->created_by,
                    logName: 'reference-configuration-import',
                    event: 'completed',
                    ipAddress: $ipAddress,
                );
            }, 3);
        } catch (Throwable $exception) {
            $batch->update([
                'status' => ImportBatchStatus::Failed,
                'failure_reason' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    private function write(ImportBatch $batch, ?string $ipAddress): void
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
            $this->commissions->saveRule(
                policyGradeId: $gradeId,
                institutionId: $institutionId,
                rateBps: (int) $row['rate_bps'],
                effectiveMonth: CarbonImmutable::parse((string) $row['effective_month']),
                actorId: $batch->created_by,
                ipAddress: $ipAddress,
                isActive: (bool) $row['is_active'],
            );
        }

        $this->agents->upsertAgents(
            $this->rows($batch, ImportProfile::Agent),
            $batch->id,
        );
        $this->agents->upsertGradeAssignments(
            $this->rows($batch, ImportProfile::GradeAssignment),
            $batch->created_by,
            $batch->id,
        );
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

    private function assertValidated(ImportBatch $batch): void
    {
        if ($batch->kind !== 'reference_configuration' || $batch->status !== ImportBatchStatus::Validated) {
            throw new RuntimeException('只有零错误且已完成事务预演的基础配置批次可以确认导入。');
        }
    }
}
