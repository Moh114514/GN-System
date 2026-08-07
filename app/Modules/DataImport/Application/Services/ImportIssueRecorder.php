<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;

final class ImportIssueRecorder
{
    /**
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $messageParameters
     */
    public function record(
        ImportBatch $batch,
        string $stage,
        string $severity,
        string $code,
        string $message,
        ?ImportFile $file = null,
        ?ImportRow $row = null,
        ?string $field = null,
        ?array $context = null,
        bool $isIgnorable = false,
        ?string $messageKey = null,
        array $messageParameters = [],
    ): ImportIssue {
        $context ??= [];
        $context['file'] ??= $file?->original_name;

        [$resolvedMessageKey, $resolvedMessageParameters] = $this->resolveMessage($code, $message, $messageKey, $messageParameters);

        return ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'import_file_id' => $file->id ?? $row?->import_file_id,
            'import_row_id' => $row?->id,
            'stage' => $stage,
            'severity' => $severity,
            'code' => $code,
            'profile' => $row?->profile->value,
            'sheet_name' => $row?->sheet_name,
            'source_row' => $row?->source_row,
            'field' => $field,
            'message' => $message,
            'message_key' => $resolvedMessageKey,
            'message_parameters' => $resolvedMessageParameters === [] ? null : $resolvedMessageParameters,
            'context_encrypted' => $context === ['file' => null] ? null : $context,
            'is_ignorable' => $isIgnorable,
        ]);
    }

    /**
     * @param  array<string, mixed>  $messageParameters
     * @return array{string, array<string, mixed>}
     */
    private function resolveMessage(string $code, string $message, ?string $messageKey, array $messageParameters): array
    {
        if ($messageKey !== null || $messageParameters !== []) {
            return [$messageKey ?? "imports.errors.{$code}", $messageParameters];
        }

        $patterns = [
            '/^机构代码“(?<institution_code>.+)”不存在。$/u' => 'institution_code_missing',
            '/^代理商“(?<agent_code>.+)”不存在。$/u' => 'agent_missing',
            '/^代理商类型“(?<agent_type>.+)”不存在。$/u' => 'agent_type_missing',
            '/^政策体系“(?<policy_system>.+)”既不存在，也未在本工作簿中提供。$/u' => 'policy_system_missing',
            '/^政策等级“(?<policy_grade>.+)”不存在。$/u' => 'policy_grade_missing',
            '/^同一工作表内存在重复键：(?<key>.+)。$/u' => 'duplicate_key',
            '/^未知机构：(?<institution>.+)$/u' => 'unknown_institution',
            '/^未知客户来源代理商：(?<agent_reference>.+)$/u' => 'unknown_customer_agent',
            '/^未知直销来源代码：(?<source_code>.+)$/u' => 'unknown_direct_source',
            '/^无法识别代理商：(?<agent_reference>.+)$/u' => 'unknown_agent',
            '/^代理商无法匹配：(?<agent_reference>.+)$/u' => 'agent_unmatched',
            '/^代理商匹配不唯一：(?<agent_reference>.+)$/u' => 'agent_ambiguous',
            '/^消费日期无法确定结算周期。$/u' => 'settlement_period_missing',
            '/^代理商 (?<agent_code>.+) 的结算周期无法确定。$/u' => 'agent_settlement_period_missing',
            '/^代理商编号 (?<agent_code>.+) 对应的代理类型 (?<agent_type>.+) 未配置或未启用。$/u' => 'agent_type_unavailable',
            '/^代理商 (?<agent_code>.+) 的月结汇总无法校验。原因：该代理商 (?<period>.+) 月的月明细全部无效。$/u' => 'monthly_summary_without_valid_details',
            '/^代理商 (?<agent_code>.+) 的 (?<period>.+) 月结汇总无法校验。原因：没有找到对应消费月份的有效月明细。$/u' => 'monthly_summary_without_matching_details',
            '/^代理商 (?<agent_code>.+) 的 (?<period>.+) 月结汇总与月明细不一致。月结汇总填写：(?<filled>.+)；月明细计算结果：(?<calculated>.+)；差额：(?<difference>.+)；参与计算的明细行：(?<rows>.+)。$/u' => 'monthly_summary_mismatch',
            '/^推广费金额不匹配，应为 (?<expected>.+) KRW。$/u' => 'commission_amount_mismatch',
            '/^无效客户编号：(?<source_code>.+)。代理客户应为“代理商编号-四位流水”，直销客户应为“WEB-六位流水”。$/u' => 'invalid_customer_code',
            '/^缺少客户编号。代理客户示例：SZ-JG-0001；直销客户示例：WEB-000001。$/u' => 'customer_code_missing',
            '/^无法从工作表名称或标题识别机构。$/u' => 'institution_unidentified',
            '/^缺少必填字段：(?<field>.+)$/u' => 'required_field',
            '/^缺少(?<field>.+)。$/u' => 'required_field_short',
            '/^无效金额：(?<value>.+)$/u' => 'invalid_amount',
            '/^(?:人民币)?金额不能为负数。$/u' => 'nonnegative_amount',
            '/^无效推广费比例：(?<value>.+)$/u' => 'invalid_commission_rate',
            '/^无效结算周期：(?<value>.+)$/u' => 'invalid_settlement_period',
            '/^无效日期：(?<value>.+)$/u' => 'invalid_date',
            '/^工作表“(?<sheet>.+)”表头不匹配，请重新下载示例核对。$/u' => 'header_mismatch',
            '/^文件“(?<file>.+)”工作表“(?<sheet>.+)”表头未识别.*。$/u' => 'header_unrecognized',
            '/^当前文件是结构示例文件，不能作为正式数据导入；请使用“可导入模拟数据”模板或填写真实脱敏数据。$/u' => 'structure_template_not_importable',
            '/^(?<field>.+)“(?<value>.+)”格式不正确；应为 (?<min>\d+)-(?<max>\d+) 位(?<characters>.+)。$/u' => 'invalid_code_format',
            '/^(?<field>.+)“(?<value>.+)”无效；请填写“是\/否”或 1\/0。$/u' => 'invalid_boolean',
            '/^(?<field>.+)“(?<value>.+)”无效；必须填写整数。$/u' => 'invalid_integer',
            '/^(?<field>.+)“(?<value>.+)”超出允许范围；应(?<range>.+)。$/u' => 'out_of_range',
            '/^(?<field>.+)“(?<value>.+)”不是有效日期；请填写 Excel 日期或 YYYY-MM-DD。$/u' => 'invalid_reference_date',
            '/^合作状态“(?<value>.+)”无效；请填写合作中、暂停或已终止。$/u' => 'invalid_cooperation_status',
        ];

        foreach ($patterns as $pattern => $key) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $parameters = [];
                foreach ($matches as $name => $value) {
                    if (is_string($name)) {
                        $parameters[$name] = $value;
                    }
                }

                return ["imports.errors.{$key}", $parameters];
            }
        }

        return ["imports.errors.{$code}", []];
    }

    public function clearRowIssues(ImportBatch $batch): void
    {
        $batch->issues()->whereNotNull('import_row_id')->delete();
    }

    /** @param array<string, mixed> $parameters */
    public function markBatchFailure(ImportBatch $batch, string $key, string $rawMessage, array $parameters = []): void
    {
        $batch->update([
            'failure_reason' => $rawMessage,
            'failure_reason_key' => str_starts_with($key, 'imports.') ? $key : "imports.errors.{$key}",
            'failure_reason_parameters' => $parameters === [] ? null : $parameters,
        ]);
    }

    public function syncRows(ImportBatch $batch, ?string $forcedStage = null, bool $clear = true): void
    {
        if ($clear) {
            $this->clearRowIssues($batch);
        }

        $existing = $batch->issues()
            ->whereNotNull('import_row_id')
            ->get(['import_row_id', 'message'])
            ->mapWithKeys(static fn (ImportIssue $issue): array => [($issue->import_row_id ?? 0).'|'.$issue->message => true]);
        foreach ($batch->rows()->with('file')->get() as $row) {
            foreach ($row->errors ?? [] as $message) {
                $message = (string) $message;
                if ($existing->has($row->id.'|'.$message)) {
                    continue;
                }

                $stage = $forcedStage ?? $this->stageFor($row);
                $this->record(
                    $batch,
                    $stage,
                    $row->status->value === 'warning' ? 'warning' : 'error',
                    $this->codeFor($stage),
                    $message,
                    $row->file,
                    $row,
                    null,
                    [
                        'raw_value' => $this->rawValue($row),
                        'normalized_value' => $row->normalized_data,
                    ],
                    $this->isIgnorable($row),
                );
            }
        }
    }

    private function stageFor(ImportRow $row): string
    {
        if ($row->profile->value === 'settlement_summary') {
            return 'summary_validation';
        }

        if (in_array($row->profile->value, ['customer_followup', 'monthly_detail'], true)) {
            return 'relation_validation';
        }

        return 'normalization';
    }

    private function codeFor(string $stage): string
    {
        return match ($stage) {
            'relation_validation' => 'relation_unresolved',
            'summary_validation' => 'summary_mismatch',
            default => 'field_validation_failed',
        };
    }

    private function rawValue(ImportRow $row): mixed
    {
        $payload = $row->raw_payload_encrypted ?? [];

        return array_key_exists('values', $payload) ? $payload['values'] : $payload;
    }

    private function isIgnorable(ImportRow $row): bool
    {
        $data = $row->normalized_data ?? [];

        return $row->status->value === 'warning'
            && $row->profile->value === 'customer_followup'
            && ! empty($data['code'])
            && empty($data['institution']);
    }
}
