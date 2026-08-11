<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Agent\Application\Contracts\ReferenceConfigurationImportGateway as AgentReferences;
use App\Modules\Config\Application\Contracts\ReferenceConfigurationImportGateway as ConfigReferences;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportOperationMode;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final readonly class ReferenceConfigurationImportParser
{
    /** @var array<string, ImportProfile> */
    private const PROFILES = [
        '代理商类型' => ImportProfile::AgentType,
        '机构及机构别名' => ImportProfile::Institution,
        '直销来源' => ImportProfile::DirectSalesSource,
        '政策体系' => ImportProfile::PolicySystem,
        '政策等级' => ImportProfile::PolicyGrade,
        '机构费率规则' => ImportProfile::CommissionRule,
        '代理商档案' => ImportProfile::Agent,
        '代理商等级分配' => ImportProfile::GradeAssignment,
    ];

    public function __construct(
        private EncryptedImportStorage $storage,
        private AgentImportGateway $agentCodes,
        private AgentReferences $agents,
        private ConfigReferences $config,
        private ImportIssueRecorder $issues,
        private ImportStageTracker $stages,
    ) {}

    public function parse(ImportBatch $batch): void
    {
        $this->stages->initialize($batch);
        $this->stages->update($batch, 'file_detection', 'running');
        $batch->update([
            'status' => ImportBatchStatus::Parsing,
            'failure_reason' => null,
            'failure_reason_key' => null,
            'failure_reason_parameters' => null,
        ]);
        $batch->issues()->delete();
        $batch->rows()->delete();
        $failureStage = 'file_detection';

        try {
            $file = $batch->files()->sole();
            $temporaryPath = storage_path('app/private/import-temp/'.Str::uuid().'.xlsx');
            if (! is_dir(dirname($temporaryPath))) {
                mkdir(dirname($temporaryPath), 0700, true);
            }
            file_put_contents($temporaryPath, $this->storage->decrypt($file->encrypted_path));

            try {
                $spreadsheet = IOFactory::load($temporaryPath);
                $present = $spreadsheet->getSheetNames();
                $missing = array_values(array_diff(array_keys(self::PROFILES), $present));
                if ($missing !== []) {
                    throw new InvalidArgumentException('缺少工作表：'.implode('、', $missing).'。请使用下载示例保留全部八个工作表。');
                }

                $preflight = ['format' => 'XLSX', 'sheets' => []];
                foreach (self::PROFILES as $sheetName => $profile) {
                    $sheet = $spreadsheet->getSheetByName($sheetName);
                    if ($sheet === null) {
                        continue;
                    }
                    $rows = $sheet->toArray(null, true, true, false);
                    $headers = array_map($this->text(...), $rows[0] ?? []);
                    $expected = ReferenceConfigurationTemplateGenerator::HEADERS[$sheetName];
                    if (array_slice($headers, 0, count($expected)) !== $expected) {
                        throw new InvalidArgumentException("工作表“{$sheetName}”表头不匹配，请重新下载示例核对。");
                    }
                    $preflight['sheets'][] = [
                        'name' => $sheetName,
                        'header_row' => 1,
                        'profile' => $profile->value,
                        'profile_label' => $profile->label(),
                        'headers' => $expected,
                    ];
                    foreach (array_slice($rows, 1, null, true) as $offset => $values) {
                        $raw = array_combine($expected, array_pad(array_slice($values, 0, count($expected)), count($expected), null));
                        if ($this->blank($raw)) {
                            continue;
                        }
                        [$status, $normalized, $errors] = $this->normalize($profile, $raw);
                        ImportRow::query()->create([
                            'import_batch_id' => $batch->id,
                            'import_file_id' => $file->id,
                            'sheet_name' => $sheetName,
                            'source_row' => $offset + 1,
                            'profile' => $profile,
                            'status' => $status,
                            'raw_payload_encrypted' => $raw,
                            'normalized_data' => $normalized,
                            'errors' => $errors,
                        ]);
                    }
                }
                $file->update([
                    'status' => 'parsed',
                    'profile' => 'reference_configuration',
                    'preflight' => $preflight,
                ]);
            } finally {
                if (isset($spreadsheet)) {
                    $spreadsheet->disconnectWorksheets();
                }
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }

            $this->stages->update($batch, 'file_detection', 'passed');
            $failureStage = 'field_validation';
            $this->issues->syncRows($batch, 'field_validation');
            $failureStage = 'relation_validation';
            $this->validateRelationships($batch);
            $this->issues->syncRows($batch, 'relation_validation', false);
            $failureStage = 'business_validation';
            $this->validateBusinessDates($batch);
            $this->issues->syncRows($batch, 'business_validation', false);
            $failureStage = 'summary_validation';
            $this->refreshCounts($batch);
        } catch (Throwable $exception) {
            $message = Str::limit($exception->getMessage(), 2000);
            $this->stages->update($batch, $failureStage, 'failed', ['issue_count' => 1]);
            $this->issues->record($batch, $failureStage, 'error', $failureStage.'_failed', $message);
            $this->issues->markBatchFailure($batch, $failureStage.'_failed', $message);
            $batch->update(['status' => ImportBatchStatus::Failed]);
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalize(ImportProfile $profile, array $raw): array
    {
        try {
            $data = match ($profile) {
                ImportProfile::AgentType => [
                    'code' => $this->code($raw['代码'], '代码', 2, 4),
                    'name' => $this->required($raw['名称'], '名称'),
                    'description' => $this->nullable($raw['说明']),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::Institution => [
                    'code' => $this->code($raw['机构代码'], '机构代码', 1, 32, true),
                    'name' => $this->required($raw['正式名称'], '正式名称'),
                    'aliases' => $this->aliases($raw['别名']),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::DirectSalesSource => [
                    'code' => $this->code($raw['代码'], '代码', 2, 6),
                    'name' => $this->required($raw['名称'], '名称'),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::PolicySystem => [
                    'name' => $this->required($raw['名称'], '名称'),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::PolicyGrade => [
                    'policy_system' => $this->required($raw['政策体系'], '政策体系'),
                    'name' => $this->required($raw['等级名称'], '等级名称'),
                    'monthly_threshold_krw' => $this->integer($raw['月业绩门槛KRW'], '月业绩门槛KRW', 0),
                    'sort_order' => $this->integer($raw['排序'], '排序', 0, 65535),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::CommissionRule => [
                    'policy_system' => $this->required($raw['政策体系'], '政策体系'),
                    'policy_grade' => $this->required($raw['等级名称'], '等级名称'),
                    'institution_code' => $this->code($raw['机构代码'], '机构代码', 1, 32, true),
                    'rate_bps' => $this->integer($raw['费率基点'], '费率基点', 0, 10000),
                    'effective_month' => $this->date($raw['生效月份'], '生效月份')->startOfMonth()->format('Y-m-d'),
                    'is_active' => $this->boolean($raw['启用'], '启用'),
                ],
                ImportProfile::Agent => [
                    'code' => $this->agentCodes->normalizeAgentCode($this->required($raw['代理商编号'], '代理商编号')),
                    'name' => $this->required($raw['代理商名称'], '代理商名称'),
                    'type_code' => $this->code($raw['代理类型代码'], '代理类型代码', 2, 4),
                    'business_role' => $this->nullable($raw['业务角色']),
                    'contact_name' => $this->nullable($raw['联系人']),
                    'contact_value' => $this->nullable($raw['联系方式']),
                    'cooperation_started_on' => $this->nullableDate($raw['合作开始日期']),
                    'cooperation_status' => $this->cooperationStatus($raw['合作状态']),
                    'notes' => $this->nullable($raw['备注']),
                    'legacy_code' => $this->nullable($raw['历史编号']),
                ],
                ImportProfile::GradeAssignment => [
                    'agent_code' => $this->agentCodes->normalizeAgentCode($this->required($raw['代理商编号'], '代理商编号')),
                    'policy_system' => $this->required($raw['政策体系'], '政策体系'),
                    'policy_grade' => $this->required($raw['等级名称'], '等级名称'),
                    'effective_month' => $this->date($raw['生效月份'], '生效月份')->startOfMonth()->format('Y-m-d'),
                    'reason' => $this->required($raw['原因'], '原因'),
                ],
                default => throw new InvalidArgumentException('不支持的基础配置工作表。'),
            };

            return [ImportRowStatus::Valid, $data, []];
        } catch (Throwable $exception) {
            return [ImportRowStatus::Error, [], [$exception->getMessage()]];
        }
    }

    private function validateRelationships(ImportBatch $batch): void
    {
        $existingAgent = $this->agents->referenceKeys();
        $types = array_fill_keys([...$existingAgent['type_codes'], ...$this->values($batch, ImportProfile::AgentType, 'code')], true);
        $systems = array_fill_keys([...$existingAgent['policy_systems'], ...$this->values($batch, ImportProfile::PolicySystem, 'name')], true);
        $grades = array_fill_keys([...$existingAgent['policy_grades'], ...$this->compoundValues($batch, ImportProfile::PolicyGrade, 'policy_system', 'name')], true);
        $institutions = array_fill_keys([...$this->config->institutionCodes(), ...$this->values($batch, ImportProfile::Institution, 'code')], true);
        $agents = array_fill_keys([...$existingAgent['agent_codes'], ...$this->values($batch, ImportProfile::Agent, 'code')], true);

        $seen = [];
        foreach ($batch->rows()->where('status', ImportRowStatus::Valid)->orderBy('id')->get() as $row) {
            $data = $row->normalized_data ?? [];
            $key = $this->uniqueKey($row->profile, $data);
            $errors = [];
            if (isset($seen[$row->profile->value][$key])) {
                $errors[] = "同一工作表内存在重复键：{$key}。";
            }
            $seen[$row->profile->value][$key] = true;

            if ($row->profile === ImportProfile::PolicyGrade && ! isset($systems[$data['policy_system']])) {
                $errors[] = "政策体系“{$data['policy_system']}”既不存在，也未在本工作簿中提供。";
            }
            if ($row->profile === ImportProfile::CommissionRule) {
                $gradeKey = "{$data['policy_system']}|{$data['policy_grade']}";
                if (! isset($grades[$gradeKey])) {
                    $errors[] = "政策等级“{$gradeKey}”不存在。";
                }
                if (! isset($institutions[$data['institution_code']])) {
                    $errors[] = "机构代码“{$data['institution_code']}”不存在。";
                }
            }
            if ($row->profile === ImportProfile::Agent && ! isset($types[$data['type_code']])) {
                $errors[] = "代理商类型“{$data['type_code']}”不存在。";
            }
            if ($row->profile === ImportProfile::GradeAssignment) {
                if (! isset($agents[$data['agent_code']])) {
                    $errors[] = "代理商“{$data['agent_code']}”不存在。";
                }
                $gradeKey = "{$data['policy_system']}|{$data['policy_grade']}";
                if (! isset($grades[$gradeKey])) {
                    $errors[] = "政策等级“{$gradeKey}”不存在。";
                }
            }
            if ($errors !== []) {
                $row->update(['status' => ImportRowStatus::Error, 'errors' => $errors]);
            }
        }
    }

    private function validateBusinessDates(ImportBatch $batch): void
    {
        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $nextMonth = $currentMonth->addMonthNoOverflow();
        $historical = $batch->operation_mode === ImportOperationMode::HistoricalCorrection;
        $cooperationMonths = $batch->rows()
            ->where('profile', ImportProfile::Agent)
            ->where('status', ImportRowStatus::Valid)
            ->get()
            ->mapWithKeys(function (ImportRow $row): array {
                $data = $row->normalized_data ?? [];

                return [(string) ($data['code'] ?? '') => isset($data['cooperation_started_on'])
                    ? CarbonImmutable::parse((string) $data['cooperation_started_on'])->startOfMonth()
                    : null];
            });

        foreach ($batch->rows()->where('status', ImportRowStatus::Valid)->whereIn('profile', [ImportProfile::CommissionRule, ImportProfile::GradeAssignment])->orderBy('id')->get() as $row) {
            $month = CarbonImmutable::parse((string) ($row->normalized_data['effective_month'] ?? ''))->startOfMonth();
            $error = null;
            if ($row->profile === ImportProfile::CommissionRule) {
                if ($historical && ! $month->lt($currentMonth)) {
                    $error = __('settlements.errors.historical_rate_month_invalid');
                } elseif (! $historical && $month->lt($currentMonth)) {
                    $error = __('imports.errors.historical_date_not_allowed', ['effective_month' => $month->format('Y-m-d')]);
                }
            } elseif ($historical) {
                if (! $month->lt($currentMonth)) {
                    $error = __('historical_correction.agents.historical_grade_month_invalid');
                } else {
                    $agentCode = (string) ($row->normalized_data['agent_code'] ?? '');
                    $cooperationMonth = $cooperationMonths->get($agentCode);
                    if ($cooperationMonth instanceof CarbonImmutable && $month->lt($cooperationMonth)) {
                        $error = __('historical_correction.agents.historical_grade_before_cooperation');
                    }
                }
            } elseif ($month->lt($currentMonth)) {
                $error = __('imports.errors.historical_date_not_allowed', ['effective_month' => $month->format('Y-m-d')]);
            } elseif ($month->eq($currentMonth)) {
                $agentCode = (string) ($row->normalized_data['agent_code'] ?? '');
                if (! $cooperationMonths->has($agentCode)) {
                    $error = __('historical_correction.agents.normal_grade_current_locked');
                }
            } elseif ($month->gt($nextMonth)) {
                $error = __('historical_correction.agents.normal_grade_future_invalid');
            }

            if ($error !== null) {
                $errors = $row->errors ?? [];
                $errors[] = $error;
                $row->update(['status' => ImportRowStatus::Error, 'errors' => $errors]);
            }
        }
    }

    private function refreshCounts(ImportBatch $batch): void
    {
        $total = $batch->rows()->count();
        $valid = $batch->rows()->where('status', ImportRowStatus::Valid)->count();
        $errors = $batch->rows()->where('status', ImportRowStatus::Error)->count();
        $summary = $batch->summary ?? [];
        foreach (self::PROFILES as $profile) {
            $summary[$profile->value] = $batch->rows()->where('profile', $profile)->count();
        }
        $fieldIssues = $batch->issues()->where('stage', 'field_validation');
        $fieldErrors = (clone $fieldIssues)->where('severity', 'error')->whereNotNull('import_row_id')->distinct()->count('import_row_id');
        $fieldWarnings = (clone $fieldIssues)->where('severity', 'warning')->whereNotNull('import_row_id')->distinct()->count('import_row_id');
        $fieldIssueRows = (clone $fieldIssues)->whereNotNull('import_row_id')->distinct()->count('import_row_id');
        $summary['stages']['field_validation'] = [
            'status' => (clone $fieldIssues)->where('severity', 'error')->exists() ? 'failed' : 'passed',
            'passed_rows' => max(0, $total - $fieldIssueRows),
            'warning_rows' => $fieldWarnings,
            'error_rows' => $fieldErrors,
        ];
        foreach (['normalization', 'relation_validation', 'business_validation', 'summary_validation'] as $stage) {
            $issueCount = $batch->issues()->where('stage', $stage)->count();
            $summary['stages'][$stage] = [
                'status' => $issueCount > 0 ? 'failed' : 'passed',
                'issue_count' => $issueCount,
            ];
        }
        $batch->update([
            'total_rows' => $total,
            'valid_rows' => $valid,
            'warning_rows' => 0,
            'error_rows' => $errors,
            'summary' => $summary,
            'status' => $total > 0 && $errors === 0 ? ImportBatchStatus::Validated : ImportBatchStatus::NeedsReview,
        ]);
    }

    /** @return array<int, string> */
    private function values(ImportBatch $batch, ImportProfile $profile, string $key): array
    {
        return $batch->rows()->where('profile', $profile)->where('status', ImportRowStatus::Valid)
            ->get()->map(fn (ImportRow $row): string => (string) ($row->normalized_data[$key] ?? ''))->all();
    }

    /** @return array<int, string> */
    private function compoundValues(ImportBatch $batch, ImportProfile $profile, string $first, string $second): array
    {
        return $batch->rows()->where('profile', $profile)->where('status', ImportRowStatus::Valid)
            ->get()->map(fn (ImportRow $row): string => ($row->normalized_data[$first] ?? '').'|'.($row->normalized_data[$second] ?? ''))->all();
    }

    /** @param array<string, mixed> $data */
    private function uniqueKey(ImportProfile $profile, array $data): string
    {
        return match ($profile) {
            ImportProfile::AgentType, ImportProfile::Institution, ImportProfile::DirectSalesSource, ImportProfile::Agent => (string) $data['code'],
            ImportProfile::PolicySystem => (string) $data['name'],
            ImportProfile::PolicyGrade => "{$data['policy_system']}|{$data['name']}",
            ImportProfile::CommissionRule => "{$data['policy_system']}|{$data['policy_grade']}|{$data['institution_code']}|{$data['effective_month']}",
            ImportProfile::GradeAssignment => "{$data['agent_code']}|{$data['effective_month']}",
            default => (string) Str::uuid(),
        };
    }

    /** @param array<string, mixed> $row */
    private function blank(array $row): bool
    {
        return count(array_filter($row, fn (mixed $value): bool => $this->text($value) !== '')) === 0;
    }

    private function text(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')) ?? '');
    }

    private function required(mixed $value, string $field): string
    {
        $value = $this->text($value);
        if ($value === '') {
            throw new InvalidArgumentException("缺少{$field}。");
        }

        return $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === '' ? null : $value;
    }

    private function code(mixed $value, string $field, int $min, int $max, bool $allowSeparators = false): string
    {
        $code = strtoupper($this->required($value, $field));
        $pattern = $allowSeparators ? "/^[A-Z0-9_-]{{$min},{$max}}$/" : "/^[A-Z0-9]{{$min},{$max}}$/";
        if (preg_match($pattern, $code) !== 1) {
            $characters = $allowSeparators ? '大写字母、数字、下划线或连字符' : '大写字母或数字';
            throw new InvalidArgumentException(
                "{$field}“{$this->display($value)}”格式不正确；应为 {$min}-{$max} 位{$characters}。",
            );
        }

        return $code;
    }

    private function boolean(mixed $value, string $field): bool
    {
        return match (mb_strtolower($this->required($value, $field))) {
            '是', '启用', 'true', '1', 'yes' => true,
            '否', '停用', 'false', '0', 'no' => false,
            default => throw new InvalidArgumentException(
                "{$field}“{$this->display($value)}”无效；请填写“是/否”或 1/0。",
            ),
        };
    }

    private function integer(mixed $value, string $field, int $min, ?int $max = null): int
    {
        $text = str_replace(',', '', $this->required($value, $field));
        if (filter_var($text, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("{$field}“{$this->display($value)}”无效；必须填写整数。");
        }
        $integer = (int) $text;
        if ($integer < $min || ($max !== null && $integer > $max)) {
            $range = $max === null ? "不小于 {$min}" : "介于 {$min} 与 {$max} 之间";
            throw new InvalidArgumentException("{$field}“{$this->display($value)}”超出允许范围；应{$range}。");
        }

        return $integer;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        try {
            return CarbonImmutable::parse($this->required($value, $field));
        } catch (Throwable) {
            throw new InvalidArgumentException(
                "{$field}“{$this->display($value)}”不是有效日期；请填写 Excel 日期或 YYYY-MM-DD。",
            );
        }
    }

    private function nullableDate(mixed $value): ?string
    {
        return $this->nullable($value) === null ? null : $this->date($value, '合作开始日期')->format('Y-m-d');
    }

    /** @return array<int, string> */
    private function aliases(mixed $value): array
    {
        $text = $this->nullable($value);
        if ($text === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $alias): string => trim($alias),
            preg_split('/[,，\r\n]+/u', $text) ?: [],
        ))));
    }

    private function cooperationStatus(mixed $value): string
    {
        return match ($this->required($value, '合作状态')) {
            '合作中', 'active' => 'active',
            '暂停', 'paused' => 'paused',
            '已终止', 'terminated' => 'terminated',
            default => throw new InvalidArgumentException(
                "合作状态“{$this->display($value)}”无效；请填写合作中、暂停或已终止。",
            ),
        };
    }

    private function display(mixed $value): string
    {
        $text = $this->text($value);

        return $text === '' ? '（空）' : $text;
    }
}
