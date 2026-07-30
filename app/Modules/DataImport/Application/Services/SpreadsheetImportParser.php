<?php

namespace App\Modules\DataImport\Application\Services;

use App\Modules\Agent\Application\Contracts\AgentImportGateway;
use App\Modules\Config\Application\Contracts\CatalogImportGateway;
use App\Modules\Customer\Application\Contracts\CustomerImportGateway;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use App\Modules\DataImport\Infrastructure\Models\ImportRow;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

final readonly class SpreadsheetImportParser
{
    public function __construct(
        private EncryptedImportStorage $storage,
        private AgentImportGateway $agents,
        private CatalogImportGateway $catalog,
        private CustomerImportGateway $customers,
    ) {}

    public function parse(ImportBatch $batch): void
    {
        $batch->update(['status' => ImportBatchStatus::Parsing, 'failure_reason' => null]);
        $batch->rows()->delete();

        try {
            foreach ($batch->files as $file) {
                $this->parseFile($file);
            }

            $this->reconcile($batch);
            $this->refreshBatchCounts($batch);
        } catch (Throwable $exception) {
            $batch->update([
                'status' => ImportBatchStatus::Failed,
                'failure_reason' => Str::limit($exception->getMessage(), 2000),
            ]);

            throw $exception;
        }
    }

    private function parseFile(ImportFile $file): void
    {
        $contents = $this->storage->decrypt($file->encrypted_path);
        $temporaryPath = storage_path('app/private/import-temp/'.Str::uuid().'.'.$file->extension);
        $encoding = 'UTF-8';
        $preflight = [
            'format' => strtoupper($file->extension),
            'encoding' => $encoding,
            'delimiter' => $file->extension === 'csv' ? ',' : null,
            'sheets' => [],
        ];

        $file->update([
            'status' => 'parsing',
            'profile' => null,
            'preflight' => null,
        ]);

        if (! is_dir(dirname($temporaryPath))) {
            mkdir(dirname($temporaryPath), 0700, true);
        }

        if ($file->extension === 'csv' && ! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'GB18030');
            $encoding = 'GB18030 → UTF-8';
            $preflight['encoding'] = $encoding;
        }

        file_put_contents($temporaryPath, $contents);

        try {
            $spreadsheet = $file->extension === 'csv'
                ? $this->loadCommaSeparatedCsv($temporaryPath)
                : IOFactory::load($temporaryPath);

            if ($this->isStructureExample($spreadsheet)) {
                throw new InvalidArgumentException(
                    '当前文件是结构示例文件，不能作为正式数据导入；请使用“可导入模拟数据”模板或填写真实脱敏数据。',
                );
            }

            $profiles = [];

            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $rows = $worksheet->toArray(null, true, true, true);
                [$profile, $headerRow, $headers] = $this->detectProfile(
                    $rows,
                    $worksheet->getTitle(),
                );

                $preflight['sheets'][] = [
                    'name' => $worksheet->getTitle(),
                    'header_row' => $headerRow > 0 ? $headerRow : null,
                    'profile' => $profile?->value,
                    'profile_label' => $profile?->label() ?? '未识别',
                    'headers' => array_values($headers),
                ];

                if ($profile === null) {
                    $file->update(['preflight' => $preflight]);
                    $delimiterHint = count($headers) <= 1
                        ? '；CSV 必须使用英文逗号分隔，请检查分隔符'
                        : '';

                    throw new InvalidArgumentException(
                        "文件“{$file->original_name}”工作表“{$worksheet->getTitle()}”表头未识别{$delimiterHint}。"
                        ."请使用结构示例核对字段，当前候选表头行：{$headerRow}。",
                    );
                }

                $profiles[] = $profile->value;

                if ($profile === ImportProfile::Codebook) {
                    continue;
                }

                $institution = $this->institutionFromSheet($spreadsheet, $worksheet->getTitle(), $rows);

                foreach ($rows as $rowNumber => $row) {
                    if ($rowNumber <= $headerRow) {
                        continue;
                    }

                    $raw = $this->mapRow($headers, $row);
                    if ($this->isBlank($raw) || $this->isTemplateRow($raw)) {
                        continue;
                    }

                    [$status, $normalized, $errors] = $this->normalize(
                        $profile,
                        $raw,
                        $institution,
                    );

                    ImportRow::query()->create([
                        'import_batch_id' => $file->import_batch_id,
                        'import_file_id' => $file->id,
                        'sheet_name' => $worksheet->getTitle(),
                        'source_row' => $rowNumber,
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
                'profile' => count(array_unique($profiles)) === 1 ? $profiles[0] : 'mixed',
                'preflight' => $preflight,
            ]);
        } catch (Throwable $exception) {
            $file->update([
                'status' => 'failed',
                'preflight' => $preflight,
            ]);

            throw $exception;
        } finally {
            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }

            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{ImportProfile|null, int, array<string, string>}
     */
    private function detectProfile(array $rows, string $sheetName): array
    {
        if ($this->isExplicitCodebook($sheetName)) {
            return [ImportProfile::Codebook, 0, []];
        }

        foreach (array_slice($rows, 0, 20, true) as $rowNumber => $row) {
            $values = array_map(fn ($value): string => $this->cleanText($value), $row);

            $profile = match (true) {
                in_array('代理商编号', $values, true) && in_array('代理商名称', $values, true)
                    && in_array('代理商等级', $values, true) => ImportProfile::SettlementSummary,
                in_array('代理商编号', $values, true) && in_array('代理商名称', $values, true) => ImportProfile::AgentArchive,
                in_array('客户编号', $values, true) && $this->containsHeader($values, '推广费比例') => ImportProfile::MonthlyDetail,
                in_array('客户姓名', $values, true) && in_array('当前阶段', $values, true) => ImportProfile::CustomerFollowup,
                default => null,
            };

            if ($profile !== null) {
                $headers = [];
                foreach ($row as $column => $value) {
                    $header = $this->cleanText($value);
                    if ($header !== '') {
                        $headers[$column] = $header;
                    }
                }

                return [$profile, (int) $rowNumber, $headers];
            }
        }

        [$candidateRow, $candidateHeaders] = $this->candidateHeader($rows);

        return [null, $candidateRow, array_combine(
            array_map(fn (int $index): string => (string) $index, array_keys($candidateHeaders)),
            $candidateHeaders,
        ) ?: []];
    }

    private function loadCommaSeparatedCsv(string $path): Spreadsheet
    {
        $reader = new Csv;
        $reader->setDelimiter(',');
        $reader->setEnclosure('"');
        $reader->setInputEncoding('UTF-8');

        return $reader->load($path);
    }

    private function isExplicitCodebook(string $sheetName): bool
    {
        return preg_match('/^(说明|导入说明|代码表|字典|codebook)$/iu', trim($sheetName)) === 1;
    }

    private function isStructureExample(Spreadsheet $spreadsheet): bool
    {
        return $this->cleanText($spreadsheet->getSheet(0)->getCell('A1')->getValue())
            === (string) config('data-import.structure_template_marker');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{int, array<int, string>}
     */
    private function candidateHeader(array $rows): array
    {
        $bestRow = 0;
        $bestHeaders = [];

        foreach (array_slice($rows, 0, 20, true) as $rowNumber => $row) {
            $headers = array_values(array_filter(
                array_map(fn ($value): string => $this->cleanText($value), $row),
                fn (string $value): bool => $value !== '',
            ));

            if (count($headers) > count($bestHeaders)) {
                $bestRow = (int) $rowNumber;
                $bestHeaders = $headers;
            }
        }

        return [$bestRow, $bestHeaders];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $headers, array $row): array
    {
        $mapped = [];
        foreach ($headers as $column => $header) {
            $mapped[$header] = $row[$column] ?? null;
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalize(ImportProfile $profile, array $raw, ?string $institution): array
    {
        try {
            return match ($profile) {
                ImportProfile::AgentArchive => $this->normalizeAgent($raw),
                ImportProfile::CustomerFollowup => $this->normalizeCustomer($raw, $institution),
                ImportProfile::MonthlyDetail => $this->normalizeMonthlyDetail($raw),
                ImportProfile::SettlementSummary => $this->normalizeSettlementSummary($raw),
                ImportProfile::Codebook => [ImportRowStatus::Ignored, [], []],
            };
        } catch (Throwable $exception) {
            return [ImportRowStatus::Error, [], [$exception->getMessage()]];
        }
    }

    /** @param array<string, mixed> $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalizeAgent(array $raw): array
    {
        $sourceCode = $this->requiredText($raw, '代理商编号');
        $code = $this->agents->normalizeAgentCode($sourceCode);
        $typeCode = (string) str($code)->afterLast('-');
        $activeTypeCodes = array_column($this->agents->activeAgentTypes(), 'code');

        if (! in_array($typeCode, $activeTypeCodes, true)) {
            throw new InvalidArgumentException("代理商编号 {$sourceCode} 对应的代理类型 {$typeCode} 未配置或未启用。");
        }

        return [ImportRowStatus::Valid, [
            'source_code' => strtoupper($sourceCode),
            'code' => $code,
            'legacy_code' => strtoupper($sourceCode) === $code ? null : strtoupper($sourceCode),
            'name' => $this->requiredText($raw, '代理商名称'),
            'business_role' => $this->text($raw, '代理类型'),
            'contact_name' => $this->text($raw, '联系人'),
            'contact_value' => $this->text($raw, '联系方式'),
            'policy_grade' => $this->text($raw, '代理等级'),
            'policy_system' => $this->text($raw, '等级体系'),
            'grade_effective_month' => $this->date($raw['等级起始月'] ?? null)?->format('Y-m-d'),
            'cooperation_started_on' => $this->date($raw['合作开始月份'] ?? null)?->format('Y-m-d'),
            'cooperation_status' => $this->cooperationStatus($this->text($raw, '合作状态')),
            'notes' => $this->text($raw, '备注'),
            'contract_number' => $this->nullableContractNumber($this->text($raw, '合同编号')),
            ...$this->contractDates($this->text($raw, '合同有效期')),
        ], []];
    }

    /** @param array<string, mixed> $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalizeCustomer(array $raw, ?string $institution): array
    {
        $sourceCode = $this->text($raw, '客户编号');
        $errors = [];
        $code = null;
        $legacyCode = null;

        if ($sourceCode === null) {
            $errors[] = '缺少客户编号。代理客户示例：SZ-JG-0001；直销客户示例：WEB-000001。';
        } else {
            $code = $this->normalizeCustomerCode($sourceCode);
            $legacyCode = strtoupper($sourceCode) === $code ? null : strtoupper($sourceCode);
        }

        if ($institution === null) {
            $errors[] = '无法从工作表名称或标题识别机构。';
        }

        return [$errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Warning, [
            'source_code' => $sourceCode,
            'code' => $code,
            'legacy_code' => $legacyCode,
            'name' => $this->requiredText($raw, '客户姓名'),
            'wechat_added_on' => $this->date($raw['加微信日期'] ?? null)?->format('Y-m-d'),
            'status' => $this->text($raw, '当前阶段'),
            'gender' => $this->text($raw, '性别'),
            'birth_date' => $this->date($raw['出生日期'] ?? null)?->format('Y-m-d'),
            'contact' => $this->text($raw, '电话/WeChat'),
            'project_intention' => $this->text($raw, '项目意向'),
            'identity_document' => $this->text($raw, '护照号/登陆证'),
            'scheduled_on' => $this->date($raw['预约到店日期'] ?? null)?->format('Y-m-d'),
            'translator_name' => $this->text($raw, '翻译匹配'),
            'institution' => $institution,
            'project_name' => $this->text($raw, '施术项目明细'),
            'amount_krw' => $this->money($raw['消费金额（韩元）'] ?? null),
            'followup_day_1' => $this->text($raw, '术后 1 天回访内容（恢复情况）'),
            'followup_day_7' => $this->text($raw, '术后 7 天回访内容（效果反馈/拍照）'),
            'followup_day_30' => $this->text($raw, '术后 30 天回访内容（最终效果+推荐意愿）'),
            'satisfaction' => $this->text($raw, '客户满意度'),
            'repurchase_intention' => $this->text($raw, '复购/转介绍意愿'),
            'followup_on' => $this->date($raw['跟进日期'] ?? null)?->format('Y-m-d'),
            'owner_name' => $this->text($raw, '负责人'),
            'notes' => $this->text($raw, '备注'),
        ], $errors];
    }

    /** @param array<string, mixed> $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalizeMonthlyDetail(array $raw): array
    {
        $amount = $this->money($raw['消费金额（KRW 韩币）'] ?? null);
        $rateBps = $this->rateBps($raw['推广费比例'] ?? null);
        $fee = $this->money($this->findValue($raw, '推广费金额'));

        return [ImportRowStatus::Valid, [
            'agent_ref' => $this->requiredText($raw, '代理商名称'),
            'customer_code' => $this->normalizeCustomerCode($this->requiredText($raw, '客户编号')),
            'customer_name' => $this->requiredText($raw, '姓名'),
            'contact' => $this->text($raw, '联系方式'),
            'institution' => $this->requiredText($raw, '意向机构'),
            'project_name' => $this->requiredText($raw, '项目'),
            'scheduled_on' => $this->date($raw['预约到院'] ?? null)?->format('Y-m-d'),
            'amount_krw' => $amount,
            'rate_bps' => $rateBps,
            'commission_krw' => $fee,
            'translator_name' => $this->text($raw, '翻译负责人'),
            'owner_name' => $this->text($raw, '负责人'),
            'notes' => $this->text($raw, '备注'),
        ], []];
    }

    /** @param array<string, mixed> $raw
     * @return array{ImportRowStatus, array<string, mixed>, array<int, string>}
     */
    private function normalizeSettlementSummary(array $raw): array
    {
        $exchangeRate = $this->decimal($raw['结算汇率'] ?? null);
        $settledOn = $this->date($raw['结算日期'] ?? null);

        return [ImportRowStatus::Valid, [
            'agent_code' => $this->agents->normalizeAgentCode($this->requiredText($raw, '代理商编号')),
            'agent_name' => $this->text($raw, '代理商名称'),
            'policy_grade' => $this->text($raw, '代理商等级'),
            'customer_count' => $this->integer($raw['月客户总数'] ?? null),
            'consumption_krw' => $this->money($this->findValue($raw, '消费总额')),
            'commission_krw' => $this->money($this->findValue($raw, '推广费总额（KRW')),
            'settled_on' => $settledOn?->format('Y-m-d'),
            'period_start' => $settledOn?->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
            'period_end' => $settledOn?->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            'exchange_rate_krw_per_cny' => $exchangeRate,
            'payout_cny_fen' => $this->cnyFen($this->findValue($raw, '推广费总额（RMB')),
            'status' => $this->settlementStatus($this->text($raw, '结算状态')),
            'notes' => $this->text($raw, '备注'),
        ], []];
    }

    private function reconcile(ImportBatch $batch): void
    {
        $agentMap = [];
        foreach ($batch->rows()->where('profile', ImportProfile::AgentArchive)->get() as $row) {
            $data = $row->normalized_data ?? [];
            if (isset($data['name'], $data['code'])) {
                $agentMap[(string) $data['name']] = (string) $data['code'];
                $agentMap[(string) ($data['source_code'] ?? $data['code'])] = (string) $data['code'];
                $agentMap[(string) $data['code']] = (string) $data['code'];
            }
        }

        foreach ($batch->rows()->where('profile', ImportProfile::CustomerFollowup)->get() as $row) {
            if ($row->status === ImportRowStatus::Error) {
                continue;
            }

            $data = $row->normalized_data ?? [];
            $code = (string) ($data['code'] ?? '');
            $errors = $row->errors ?? [];

            if (preg_match('/^([A-Z0-9]{2,6})-\d{6}$/', $code, $matches) === 1) {
                if ($this->customers->resolveDirectSalesSourceId($matches[1]) === null) {
                    $errors[] = "未知直销来源代码：{$matches[1]}";
                }
            } elseif (preg_match('/^(.+)-\d{4}$/', $code, $matches) === 1) {
                $agentReference = $matches[1];
                if (! isset($agentMap[$agentReference])
                    && $this->agents->resolveAgentId($agentReference) === null) {
                    $errors[] = "未知客户来源代理商：{$agentReference}";
                }
            }

            $institution = (string) ($data['institution'] ?? '');
            if ($institution !== '' && $this->catalog->resolveInstitutionId($institution) === null) {
                $errors[] = "未知机构：{$institution}";
            }

            $row->update([
                'errors' => array_values(array_unique($errors)),
                'status' => $errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Error,
            ]);
        }

        $aggregates = [];
        foreach ($batch->rows()->where('profile', ImportProfile::MonthlyDetail)->get() as $row) {
            if ($row->status === ImportRowStatus::Error) {
                continue;
            }

            $data = $row->normalized_data ?? [];
            $reference = (string) ($data['agent_ref'] ?? '');
            $agentCode = $agentMap[$reference] ?? null;

            if ($agentCode === null) {
                try {
                    $agentCode = $this->agents->normalizeAgentCode($reference);
                } catch (InvalidArgumentException) {
                    $agentId = $this->agents->resolveAgentId($reference);
                    $agentCode = $agentId === null ? null : $reference;
                }
            }

            $errors = $row->errors ?? [];
            if ($agentCode === null) {
                $errors[] = "无法识别代理商：{$reference}";
            }

            $expected = BigDecimal::of((int) ($data['amount_krw'] ?? 0))
                ->multipliedBy((int) ($data['rate_bps'] ?? 0))
                ->dividedBy(10000, 0, RoundingMode::HalfUp)
                ->toInt();
            if ($expected !== (int) ($data['commission_krw'] ?? 0)) {
                $errors[] = "推广费金额不匹配，应为 {$expected} KRW。";
            }

            $institution = (string) ($data['institution'] ?? '');
            if ($institution !== '' && $this->catalog->resolveInstitutionId($institution) === null) {
                $errors[] = "未知机构：{$institution}";
            }

            $data['agent_code'] = $agentCode;
            $row->update([
                'normalized_data' => $data,
                'errors' => $errors,
                'status' => $errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Error,
            ]);

            if ($errors === [] && $agentCode !== null) {
                $aggregates[$agentCode] ??= ['count' => 0, 'consumption_krw' => 0, 'commission_krw' => 0];
                $aggregates[$agentCode]['count']++;
                $aggregates[$agentCode]['consumption_krw'] += (int) $data['amount_krw'];
                $aggregates[$agentCode]['commission_krw'] += (int) $data['commission_krw'];
            }
        }

        foreach ($batch->rows()->where('profile', ImportProfile::SettlementSummary)->get() as $row) {
            $data = $row->normalized_data ?? [];
            $code = (string) ($data['agent_code'] ?? '');
            $actual = $aggregates[$code] ?? ['count' => 0, 'consumption_krw' => 0, 'commission_krw' => 0];
            $errors = $row->errors ?? [];

            foreach (['count' => 'customer_count', 'consumption_krw' => 'consumption_krw', 'commission_krw' => 'commission_krw'] as $actualKey => $sourceKey) {
                if ((int) $actual[$actualKey] !== (int) ($data[$sourceKey] ?? 0)) {
                    $errors[] = "月结汇总与明细不一致：{$sourceKey} 应为 {$actual[$actualKey]}。";
                }
            }

            $row->update([
                'errors' => $errors,
                'status' => $errors === [] ? ImportRowStatus::Valid : ImportRowStatus::Error,
            ]);
        }
    }

    private function refreshBatchCounts(ImportBatch $batch): void
    {
        $counts = $batch->rows()
            ->select('status', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $valid = (int) ($counts[ImportRowStatus::Valid->value] ?? 0);
        $warnings = (int) ($counts[ImportRowStatus::Warning->value] ?? 0)
            + (int) ($counts[ImportRowStatus::DuplicateCandidate->value] ?? 0);
        $errors = (int) ($counts[ImportRowStatus::Error->value] ?? 0);

        $batch->update([
            'total_rows' => $batch->rows()->count(),
            'valid_rows' => $valid,
            'warning_rows' => $warnings,
            'error_rows' => $errors,
            'status' => ($warnings + $errors) > 0
                ? ImportBatchStatus::NeedsReview
                : ImportBatchStatus::Validated,
            'summary' => ['profiles' => $batch->rows()->selectRaw('profile, COUNT(*) AS count')->groupBy('profile')->pluck('count', 'profile')],
        ]);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function containsHeader(array $values, string $needle): bool
    {
        return collect($values)->contains(fn (string $value): bool => str_contains($value, $needle));
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function isBlank(array $raw): bool
    {
        return collect($raw)->every(fn ($value): bool => $this->cleanText($value) === '');
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function isTemplateRow(array $raw): bool
    {
        return collect($raw)->contains(function ($value): bool {
            $text = $this->cleanText($value);

            return str_starts_with($text, '示例：') || str_contains($text, '#VALUE!');
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function institutionFromSheet(Spreadsheet $spreadsheet, string $sheetName, array $rows): ?string
    {
        if ($spreadsheet->getSheetCount() > 1 && $this->catalog->resolveInstitutionId($sheetName) !== null) {
            return $sheetName;
        }

        $firstRow = $rows[1] ?? [];
        $title = $this->cleanText($firstRow['A'] ?? array_values($firstRow)[0] ?? null);
        if (str_contains($title, '· 客户跟进表')) {
            return trim(strstr($title, '· 客户跟进表', true) ?: $title);
        }

        return $this->catalog->resolveInstitutionId($sheetName) !== null ? $sheetName : null;
    }

    private function cleanText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
    }

    private function normalizeCustomerCode(string $sourceCode): string
    {
        try {
            return $this->agents->normalizeCustomerCode($sourceCode);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(
                "无效客户编号：{$sourceCode}。代理客户应为“代理商编号-四位流水”，"
                .'例如 SZ-JG-0001；直销客户应为“2–6 位大写来源代码-六位流水”，例如 WEB-000001。',
            );
        }
    }

    /** @param array<string, mixed> $raw */
    private function requiredText(array $raw, string $key): string
    {
        $value = $this->text($raw, $key);
        if ($value === null) {
            throw new InvalidArgumentException("缺少必填字段：{$key}");
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private function text(array $raw, string $key): ?string
    {
        $value = $this->cleanText($raw[$key] ?? null);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $raw */
    private function findValue(array $raw, string $partialHeader): mixed
    {
        foreach ($raw as $header => $value) {
            if (str_contains($header, $partialHeader)) {
                return $value;
            }
        }

        return null;
    }

    private function money(mixed $value): int
    {
        $text = str_replace([',', ' ', '₩'], '', $this->cleanText($value));
        if ($text === '') {
            return 0;
        }

        if (! is_numeric($text)) {
            throw new InvalidArgumentException("无效金额：{$value}");
        }

        $amount = BigDecimal::of($text)->toScale(0, RoundingMode::HalfUp)->toInt();
        if ($amount < 0) {
            throw new InvalidArgumentException('金额不能为负数。');
        }

        return $amount;
    }

    private function integer(mixed $value): int
    {
        return $this->money($value);
    }

    private function rateBps(mixed $value): int
    {
        $text = $this->cleanText($value);
        $basisPoints = str_contains($text, '%')
            ? BigDecimal::of(str_replace('%', '', $text))
                ->multipliedBy(100)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt()
            : BigDecimal::of($text)
                ->multipliedBy(10000)
                ->toScale(0, RoundingMode::HalfUp)
                ->toInt();

        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new InvalidArgumentException("无效推广费比例：{$value}");
        }

        return $basisPoints;
    }

    private function decimal(mixed $value): ?string
    {
        $text = str_replace(',', '', $this->cleanText($value));

        if ($text === '') {
            return null;
        }

        return (string) BigDecimal::of($text);
    }

    private function cnyFen(mixed $value): int
    {
        $text = str_replace([',', ' ', '¥', '￥'], '', $this->cleanText($value));
        if ($text === '') {
            return 0;
        }

        $amount = BigDecimal::of($text)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();

        if ($amount < 0) {
            throw new InvalidArgumentException('人民币金额不能为负数。');
        }

        return $amount;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $this->cleanText($value) === '') {
            return null;
        }

        if (is_numeric($value)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        try {
            return CarbonImmutable::parse(str_replace(['年', '月', '日'], ['-', '-', ''], $this->cleanText($value)))->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException("无效日期：{$value}");
        }
    }

    private function cooperationStatus(?string $value): string
    {
        return match (true) {
            $value !== null && str_contains($value, '暂停') => 'paused',
            $value !== null && str_contains($value, '终止') => 'terminated',
            default => 'active',
        };
    }

    private function settlementStatus(?string $value): string
    {
        return match (true) {
            $value !== null && str_contains($value, '结清') => 'paid',
            $value !== null && str_contains($value, '对账') => 'reconciled',
            default => 'draft',
        };
    }

    private function nullableContractNumber(?string $value): ?string
    {
        return $value === null || str_contains($value, '待签') ? null : $value;
    }

    /**
     * @return array{contract_valid_from: string|null, contract_valid_until: string|null}
     */
    private function contractDates(?string $value): array
    {
        if ($value === null || preg_match('/(\d{4}-\d{2})\s*至\s*(\d{4}-\d{2})/', $value, $matches) !== 1) {
            return ['contract_valid_from' => null, 'contract_valid_until' => null];
        }

        return [
            'contract_valid_from' => CarbonImmutable::parse($matches[1].'-01')->format('Y-m-d'),
            'contract_valid_until' => CarbonImmutable::parse($matches[2].'-01')->endOfMonth()->format('Y-m-d'),
        ];
    }
}
