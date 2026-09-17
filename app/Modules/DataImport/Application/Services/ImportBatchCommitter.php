<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Data\AgentImportData;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\Customer\Application\Data\CustomerImportData;
use App\Modules\DataImport\Application\Exceptions\DryRunRollback;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use App\Modules\Order\Application\Contracts\OrderImportGateway;
use App\Modules\Order\Application\Data\OrderImportData;
use App\Modules\Reminder\Application\Contracts\FollowupImportGateway;
use App\Modules\Reminder\Application\Data\FollowupImportData;
use App\Modules\Settlement\Application\Contracts\SettlementImportGateway;
use App\Modules\Settlement\Application\Data\CommissionImportData;
use App\Modules\Settlement\Application\Data\SettlementImportData;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ImportBatchCommitter
{
    public function __construct(
        private CatalogImportGateway $catalog,
        private AgentImportGateway $agents,
        private CustomerImportGateway $customers,
        private OrderImportGateway $orders,
        private FollowupImportGateway $followups,
        private SettlementImportGateway $settlements,
        private AuditRecorder $audit,
        private ImportIssueRecorder $issues,
        private ImportStageTracker $stages,
    ) {}

    public function dryRun(ImportBatch $batch, int $limit = 100): void
    {
        $this->stages->update($batch, 'dry_run', 'running');
        try {
            if (($batch->kind ?? 'historical') !== 'historical' || $batch->status !== ImportBatchStatus::Validated) {
                throw new RuntimeException('只有已验证批次可以执行事务预演。');
            }

            DB::transaction(function () use ($batch, $limit): void {
                $includedIds = $batch->rows()
                    ->where('status', ImportRowStatus::Valid)
                    ->orderBy('id')
                    ->limit($limit)
                    ->pluck('id');

                $batch->rows()
                    ->where('status', ImportRowStatus::Valid)
                    ->whereNotIn('id', $includedIds)
                    ->update(['status' => ImportRowStatus::Ignored]);

                $this->commitAgents($batch);
                $this->commitCustomers($batch);
                $this->commitMonthlyDetails($batch);
                $this->commitSettlements($batch);
                $this->settlements->materializeHistoricalItems($batch->id);

                throw new DryRunRollback;
            });
        } catch (DryRunRollback) {
            $summary = $batch->summary ?? [];
            $summary['dry_run_rows'] = min($batch->valid_rows, $limit);
            $summary['dry_run_completed_at'] = now()->toIso8601String();
            $summary['stages']['dry_run'] = [
                'status' => 'passed',
                'passed_rows' => min($batch->valid_rows, $limit),
            ];
            $batch->update(['summary' => $summary]);
        } catch (\Throwable $exception) {
            $this->issues->record($batch, 'dry_run', 'error', 'dry_run_failed', $exception->getMessage(), null, null, null, [
                'exception' => $exception::class,
            ]);
            $this->issues->markBatchFailure($batch, 'dry_run_failed', $exception->getMessage());
            $this->stages->update($batch, 'dry_run', 'failed', ['issue_count' => 1]);
            throw $exception;
        }
    }

    public function commit(ImportBatch $batch): void
    {
        try {
            if (($batch->kind ?? 'historical') !== 'historical' || $batch->status !== ImportBatchStatus::Validated) {
                throw new RuntimeException('只有零错误、零待处理项的已验证批次可以正式导入。');
            }

            $this->assertDryRunPassed($batch);
            $batch->update(['status' => ImportBatchStatus::Committing]);
            $this->stages->update($batch, 'commit', 'running');
            DB::transaction(function () use ($batch): void {
                $this->commitAgents($batch);
                $this->commitCustomers($batch);
                $this->commitMonthlyDetails($batch);
                $this->commitSettlements($batch);
                $this->settlements->materializeHistoricalItems($batch->id);

                $completedAt = now();
                $batch->update([
                    'status' => ImportBatchStatus::Completed,
                    'completed_at' => $completedAt,
                    'rollback_expires_at' => $completedAt->copy()->addHours((int) config('data-import.rollback_hours', 24)),
                ]);

                $this->audit->record('完成历史数据导入', [
                    'import_batch_id' => $batch->id,
                    'valid_rows' => $batch->valid_rows,
                ], $batch->created_by);
            }, 3);
            $this->stages->update($batch, 'commit', 'passed', ['passed_rows' => $batch->valid_rows]);
        } catch (\Throwable $exception) {
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

    private function assertDryRunPassed(ImportBatch $batch): void
    {
        $status = $batch->summary['stages']['dry_run']['status'] ?? null;
        if ($status !== 'passed') {
            throw new RuntimeException('正式导入前必须完成并通过事务预演。');
        }
    }

    private function commitAgents(ImportBatch $batch): void
    {
        foreach ($this->rows($batch, ImportProfile::AgentArchive) as $row) {
            $data = $row->normalized_data ?? [];
            $this->agents->upsertAgent(new AgentImportData(
                code: (string) $data['source_code'],
                name: (string) $data['name'],
                businessRole: (string) ($data['business_role'] ?? ''),
                contactName: $this->nullableString($data['contact_name'] ?? null),
                contactValue: $this->nullableString($data['contact_value'] ?? null),
                policySystem: $this->nullableString($data['policy_system'] ?? null),
                policyGrade: $this->nullableString($data['policy_grade'] ?? null),
                gradeEffectiveMonth: $this->carbon($data['grade_effective_month'] ?? null),
                cooperationStartedOn: $this->carbon($data['cooperation_started_on'] ?? null),
                cooperationStatus: (string) ($data['cooperation_status'] ?? 'active'),
                notes: $this->nullableString($data['notes'] ?? null),
                contractNumber: $this->nullableString($data['contract_number'] ?? null),
                contractValidFrom: $this->carbon($data['contract_valid_from'] ?? null),
                contractValidUntil: $this->carbon($data['contract_valid_until'] ?? null),
                importBatchId: $batch->id,
            ));
        }
    }

    private function commitCustomers(ImportBatch $batch): void
    {
        foreach ($this->rows($batch, ImportProfile::CustomerFollowup) as $row) {
            $data = $row->normalized_data ?? [];
            $code = $this->requiredString($data, 'code');
            $agentId = $this->agentIdFromCustomerCode($code);

            if ($this->customers->resolveCustomerId($code) === null) {
                $candidates = $this->customers->duplicateCandidateIds(
                    $this->nullableString($data['contact'] ?? null),
                    $this->nullableString($data['identity_document'] ?? null),
                );

                if ($candidates !== []) {
                    throw new RuntimeException("客户 {$code} 存在疑似重复记录，必须先人工确认。");
                }
            }

            $customerId = $this->customers->upsertCustomer(new CustomerImportData(
                code: $code,
                legacyCode: $this->nullableString($data['legacy_code'] ?? null),
                name: $this->requiredString($data, 'name'),
                gender: $this->nullableString($data['gender'] ?? null),
                birthDate: $this->carbon($data['birth_date'] ?? null),
                sourceAgentId: $agentId,
                statusName: $this->nullableString($data['status'] ?? null),
                wechatAddedOn: $this->carbon($data['wechat_added_on'] ?? null),
                contactValue: $this->nullableString($data['contact'] ?? null),
                identityDocument: $this->nullableString($data['identity_document'] ?? null),
                projectIntention: $this->nullableString($data['project_intention'] ?? null),
                notes: $this->nullableString($data['notes'] ?? null),
                importBatchId: $batch->id,
            ));

            $orderId = null;
            $amountKrw = (int) ($data['amount_krw'] ?? 0);
            $projectName = $this->nullableString($data['project_name'] ?? null);
            $institutionName = $this->nullableString($data['institution'] ?? null);
            if ($amountKrw > 0 && $projectName !== null && $institutionName !== null) {
                $institutionId = $this->institutionId($institutionName);
                $orderId = $this->orders->upsertOrder(new OrderImportData(
                    customerId: $customerId,
                    institutionId: $institutionId,
                    agentId: $agentId,
                    projectName: $projectName,
                    amountKrw: $amountKrw,
                    scheduledAt: $this->carbon($data['scheduled_on'] ?? null),
                    completedOn: $this->carbon($data['scheduled_on'] ?? null),
                    translatorName: $this->nullableString($data['translator_name'] ?? null),
                    notes: $this->nullableString($data['notes'] ?? null),
                    importBatchId: $batch->id,
                ));
            }

            foreach ([
                'day_1' => $data['followup_day_1'] ?? null,
                'day_7' => $data['followup_day_7'] ?? null,
                'day_30' => $data['followup_day_30'] ?? null,
            ] as $type => $content) {
                if ($this->nullableString($content) !== null) {
                    $this->followups->record(new FollowupImportData(
                        customerId: $customerId,
                        orderId: $orderId,
                        type: $type,
                        followedUpOn: $this->carbon($data['followup_on'] ?? null),
                        content: (string) $content,
                        importBatchId: $batch->id,
                    ));
                }
            }
        }
    }

    private function commitMonthlyDetails(ImportBatch $batch): void
    {
        foreach ($this->rows($batch, ImportProfile::MonthlyDetail) as $row) {
            $data = $row->normalized_data ?? [];
            $agentCode = $this->requiredString($data, 'agent_code');
            $agentId = $this->agents->resolveAgentId($agentCode);
            if ($agentId === null) {
                throw new RuntimeException("找不到代理商：{$agentCode}");
            }

            $customerCode = $this->requiredString($data, 'customer_code');
            $customerId = $this->customers->resolveCustomerId($customerCode);
            if ($customerId === null) {
                $customerId = $this->customers->upsertCustomer(new CustomerImportData(
                    code: $customerCode,
                    legacyCode: null,
                    name: $this->requiredString($data, 'customer_name'),
                    gender: null,
                    birthDate: null,
                    sourceAgentId: $agentId,
                    statusName: null,
                    wechatAddedOn: null,
                    contactValue: $this->nullableString($data['contact'] ?? null),
                    identityDocument: null,
                    projectIntention: $this->nullableString($data['project_name'] ?? null),
                    notes: '由历史月明细补建',
                    importBatchId: $batch->id,
                ));
            }

            $orderId = $this->orders->upsertOrder(new OrderImportData(
                customerId: $customerId,
                institutionId: $this->institutionId($this->requiredString($data, 'institution')),
                agentId: $agentId,
                projectName: $this->requiredString($data, 'project_name'),
                amountKrw: (int) $data['amount_krw'],
                scheduledAt: $this->carbon($data['scheduled_on'] ?? null),
                completedOn: $this->carbon($data['scheduled_on'] ?? null),
                translatorName: $this->nullableString($data['translator_name'] ?? null),
                notes: $this->nullableString($data['notes'] ?? null),
                importBatchId: $batch->id,
            ));

            $this->settlements->recordCommission(new CommissionImportData(
                orderId: $orderId,
                agentId: $agentId,
                rateBps: (int) $data['rate_bps'],
                amountKrw: (int) $data['commission_krw'],
                ruleSnapshot: [
                    'source' => 'historical_import',
                    'institution' => $data['institution'],
                    'rate_bps' => (int) $data['rate_bps'],
                ],
                overrideReason: '历史明细保留原比例',
                importBatchId: $batch->id,
            ));
        }
    }

    private function commitSettlements(ImportBatch $batch): void
    {
        foreach ($this->rows($batch, ImportProfile::SettlementSummary) as $row) {
            $data = $row->normalized_data ?? [];
            $agentId = $this->agents->resolveAgentId($this->requiredString($data, 'agent_code'));
            if ($agentId === null) {
                throw new RuntimeException("月结汇总找不到代理商：{$data['agent_code']}");
            }

            $periodStart = $this->carbon($data['period_start'] ?? null);
            $periodEnd = $this->carbon($data['period_end'] ?? null);
            if ($periodStart === null || $periodEnd === null) {
                throw new RuntimeException('月结汇总缺少可推导结算周期的结算日期。');
            }

            $agent = $this->agents->resolveAgentReference($this->requiredString($data, 'agent_code'));
            $this->settlements->upsertSettlement(new SettlementImportData(
                agentId: $agentId,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                settledOn: $this->carbon($data['settled_on'] ?? null),
                exchangeRateKrwPerCny: $this->nullableString($data['exchange_rate_krw_per_cny'] ?? null),
                totalConsumptionKrw: (int) $data['consumption_krw'],
                totalCommissionKrw: (int) $data['commission_krw'],
                payoutAmountCnyFen: (int) $data['payout_cny_fen'],
                status: (string) $data['status'],
                importBatchId: $batch->id,
                agentSnapshot: $agent === null ? null : ['id' => $agent->id, 'code' => $agent->code, 'name' => $agent->name],
            ));
        }
    }

    /** @return iterable<int, ImportRow> */
    private function rows(ImportBatch $batch, ImportProfile $profile): iterable
    {
        return $batch->rows()
            ->where('profile', $profile)
            ->where('status', ImportRowStatus::Valid)
            ->orderBy('id')
            ->cursor();
    }

    private function agentIdFromCustomerCode(string $code): int
    {
        if (preg_match('/^(.+)-\d{4}$/', $code, $matches) !== 1) {
            throw new RuntimeException("无法从客户编号识别来源：{$code}");
        }

        $agentId = $this->agents->resolveAgentId($matches[1]);
        if ($agentId === null) {
            throw new RuntimeException("找不到客户来源代理商：{$matches[1]}");
        }

        return $agentId;
    }

    private function institutionId(string $name): int
    {
        $id = $this->catalog->resolveInstitutionId($name);
        if ($id === null) {
            throw new RuntimeException("找不到机构：{$name}");
        }

        return $id;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = $this->nullableString($data[$key] ?? null);
        if ($value === null) {
            throw new RuntimeException("导入数据缺少 {$key}。");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function carbon(mixed $value): ?CarbonImmutable
    {
        return $this->nullableString($value) === null ? null : CarbonImmutable::parse((string) $value);
    }
}
