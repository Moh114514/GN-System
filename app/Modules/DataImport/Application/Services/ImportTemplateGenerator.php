<?php

namespace App\Modules\DataImport\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final readonly class ImportTemplateGenerator
{
    public function __construct(private ImportReferenceReadiness $readiness) {}

    public function structureExample(): string
    {
        $spreadsheet = new Spreadsheet;
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('说明');
        $instructions->fromArray([
            [(string) config('data-import.structure_template_marker')],
            ['用途', '仅用于核对工作表、表头和客户编号格式，不可直接导入。'],
            ['代理客户编号', '代理商编号-四位流水，例如 SZ-JG-0001'],
            ['CSV 分隔符', '必须使用英文逗号（,）'],
        ]);

        $this->addSheet($spreadsheet, '代理商档案', $this->agentHeaders(), [
            '示例：SZ-JG', '示例：代理商名称', '示例：旅行社',
        ]);
        $this->addSheet($spreadsheet, '示例机构 · 客户跟进表', $this->customerHeaders(), [
            '示例：SZ-JG-0001', '示例：客户姓名',
        ]);
        $this->addSheet($spreadsheet, '代理商月明细', $this->detailHeaders(), [
            '示例：代理商名称', '示例：SZ-JG-0001',
        ]);
        $this->addSheet($spreadsheet, '代理商月结汇总', $this->settlementHeaders(), [
            '示例：SZ-JG', '示例：代理商名称',
        ]);

        return $this->save($spreadsheet, 'import-structure');
    }

    public function importableSimulation(): string
    {
        $this->readiness->assertReady();
        $references = $this->readiness->inspect();
        $type = $references['agent_types'][0];
        $institution = $references['institutions'][0];
        $today = CarbonImmutable::today();
        $prefix = 'T'.$today->format('ymd');
        $agentCode = "{$prefix}-{$type['code']}";
        $agentCustomerCode = "{$agentCode}-0001";
        $completedOn = $today->subMonthNoOverflow()->startOfMonth()->addDays(9);
        $settledOn = $completedOn->addMonthNoOverflow()->startOfMonth()->addDays(4);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->addSheet($spreadsheet, '代理商档案', $this->agentHeaders(), [
            $agentCode,
            '【模拟】历史导入代理商',
            $type['name'],
            '模拟联系人',
            '',
            '',
            '',
            '',
            $completedOn->startOfMonth()->format('Y-m-d'),
            '合作中',
            '可导入模拟数据',
            '',
            '',
        ]);

        $customerSheet = $this->addSheet(
            $spreadsheet,
            '客户跟进',
            $this->customerHeaders(),
            null,
        );
        $customerSheet->removeRow(1);
        $customerSheet->fromArray([[$institution['name'].' · 客户跟进表']], null, 'A1');
        $customerSheet->fromArray([$this->customerHeaders()], null, 'A2');
        $customerSheet->fromArray([[
            $agentCustomerCode,
            '【模拟】代理客户',
            $today->format('Y-m-d'),
            '已预约',
            '',
            '',
            '',
            '模拟项目',
            '',
            '',
            '',
            '',
            0,
            '',
            '',
            '',
            '',
            '',
            $today->format('Y-m-d'),
            'UAT',
            '可导入模拟数据',
        ]], null, 'A3');
        $this->formatSheet($customerSheet);

        $this->addSheet($spreadsheet, '代理商月明细', $this->detailHeaders(), [
            $agentCode,
            $agentCustomerCode,
            '【模拟】代理客户',
            '',
            $institution['name'],
            '模拟项目',
            $completedOn->format('Y-m-d'),
            1000000,
            '10%',
            100000,
            '',
            'UAT',
            '可导入模拟数据',
        ]);

        $this->addSheet($spreadsheet, '代理商月结汇总', $this->settlementHeaders(), [
            $agentCode,
            '【模拟】历史导入代理商',
            '',
            $completedOn->format('Y-m'),
            1,
            1000000,
            100000,
            $settledOn->format('Y-m-d'),
            200,
            500,
            '已对账',
            '可导入模拟数据',
        ]);

        return $this->save($spreadsheet, 'import-simulation');
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, mixed>|null  $row
     */
    private function addSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $headers,
        ?array $row,
    ): Worksheet {
        $sheet = new Worksheet($spreadsheet, $title);
        $spreadsheet->addSheet($sheet);
        $sheet->fromArray([$headers], null, 'A1');

        if ($row !== null) {
            $sheet->fromArray([$row], null, 'A2');
        }

        $this->formatSheet($sheet);

        return $sheet;
    }

    private function formatSheet(Worksheet $sheet): void
    {
        $highestColumn = $sheet->getHighestColumn();
        $sheet->freezePane('A2');
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);

        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }

    private function save(Spreadsheet $spreadsheet, string $prefix): string
    {
        $directory = storage_path('app/private/import-templates');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory.'/'.$prefix.'-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /** @return array<int, string> */
    private function agentHeaders(): array
    {
        return [
            '代理商编号', '代理商名称', '代理类型', '联系人', '联系方式', '代理等级',
            '等级体系', '等级起始月', '合作开始月份', '合作状态', '备注', '合同编号', '合同有效期',
        ];
    }

    /** @return array<int, string> */
    private function customerHeaders(): array
    {
        return [
            '客户编号', '客户姓名', '加微信日期', '当前阶段', '性别', '出生日期', '电话/WeChat',
            '项目意向', '护照号/登陆证', '预约到店日期', '翻译匹配', '施术项目明细', '消费金额（韩元）',
            '术后 1 天回访内容（恢复情况）', '术后 7 天回访内容（效果反馈/拍照）',
            '术后 30 天回访内容（最终效果+推荐意愿）', '客户满意度', '复购/转介绍意愿',
            '跟进日期', '负责人', '备注',
        ];
    }

    /** @return array<int, string> */
    private function detailHeaders(): array
    {
        return [
            '代理商名称', '客户编号', '姓名', '联系方式', '意向机构', '项目', '预约到院',
            '消费金额（KRW 韩币）', '推广费比例', '推广费金额（KRW 韩币）', '翻译负责人', '负责人', '备注',
        ];
    }

    /** @return array<int, string> */
    private function settlementHeaders(): array
    {
        return [
            '代理商编号', '代理商名称', '代理商等级', '结算周期', '月客户总数', '消费总额（KRW)',
            '推广费总额（KRW)', '结算日期', '结算汇率', '推广费总额（RMB 元）', '结算状态', '备注',
        ];
    }
}
