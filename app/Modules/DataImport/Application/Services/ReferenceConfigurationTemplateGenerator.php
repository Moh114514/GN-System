<?php

namespace App\Modules\DataImport\Application\Services;

use App\Infrastructure\Time\BusinessClock;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final readonly class ReferenceConfigurationTemplateGenerator
{
    /** @var array<string, array<int, string>> */
    public const HEADERS = [
        '代理商类型' => ['代码', '名称', '说明', '启用'],
        '机构及机构别名' => ['机构代码', '正式名称', '别名', '启用'],
        '政策体系' => ['名称', '启用'],
        '政策等级' => ['政策体系', '等级名称', '排序', '启用'],
        '机构费率规则' => ['政策体系', '等级名称', '机构代码', '费率基点', '生效月份', '启用'],
        '代理商档案' => ['代理商编号', '代理商名称', '代理类型代码', '业务角色', '联系人', '联系方式', '合作开始日期', '合作状态', '备注', '历史编号'],
        '代理商等级分配' => ['代理商编号', '政策体系', '等级名称', '生效月份', '原因'],
    ];

    public function __construct(private BusinessClock $clock) {}

    public function example(): string
    {
        $today = $this->clock->now();
        $month = $today->addMonthNoOverflow()->startOfMonth()->format('Y-m-d');
        $spreadsheet = new Spreadsheet;
        $instructions = $spreadsheet->getActiveSheet();
        $instructions->setTitle('填写说明');
        $instructions->fromArray([
            ['基础配置导入示例'],
            ['处理顺序', '基础字典 → 政策等级 → 费率 → 代理商 → 等级分配'],
            ['使用方式', '保留七个工作表及表头；删除不需要的示例行后填写。空工作表允许保留。'],
            ['启用', '填写“是/否”或 1/0。'],
            ['机构别名', '同一单元格内使用英文逗号、中文逗号或换行分隔。'],
            ['费率基点', '100 基点 = 1%，例如 1200 = 12%。'],
            ['生效月份', '填写月份第一天，例如 '.$month.'。'],
            ['代理商编号', '格式为“简称-代理类型代码”，例如 UATP5-JG。'],
            ['历史编号', '每个代理商仅支持一个 legacy_code；当前系统不支持代理商多别名。'],
            ['确认机制', '上传只做预览与校验；管理员必须再次确认才会写入。'],
        ]);
        $this->format($instructions);

        $examples = [
            '代理商类型' => ['UAT', 'UAT 代理', '基础配置导入示例类型', '是'],
            '机构及机构别名' => ['UAT-HOSP', 'UAT 示例机构', '示例医院,UAT医院', '是'],
            '政策体系' => ['UAT 示例政策', '是'],
            '政策等级' => ['UAT 示例政策', 'UAT 银级', 10, '是'],
            '机构费率规则' => ['UAT 示例政策', 'UAT 银级', 'UAT-HOSP', 1200, $month, '是'],
            '代理商档案' => ['UATP5-UAT', 'UAT 示例代理商', 'UAT', '机构合作', 'UAT 联系人', 'uat@example.invalid', $today->format('Y-m-d'), '合作中', '请替换为脱敏数据', ''],
            '代理商等级分配' => ['UATP5-UAT', 'UAT 示例政策', 'UAT 银级', $month, '基础配置导入'],
        ];

        $examples[array_key_last($examples)][3] = $today->startOfMonth()->format('Y-m-d');

        foreach (self::HEADERS as $title => $headers) {
            $sheet = new Worksheet($spreadsheet, $title);
            $spreadsheet->addSheet($sheet);
            $sheet->fromArray([$headers, $examples[$title]], null, 'A1');
            $this->format($sheet);
        }

        $directory = storage_path('app/private/import-templates');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/reference-configuration-'.Str::uuid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function format(Worksheet $sheet): void
    {
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
    }
}
