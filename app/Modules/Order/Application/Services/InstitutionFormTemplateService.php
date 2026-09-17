<?php

namespace App\Modules\Order\Application\Services;

use App\Modules\Config\Application\Contracts\InstitutionReferenceReader;
use App\Modules\Customer\Application\Contracts\CustomerOrderReferenceReader;
use App\Modules\Order\Infrastructure\Models\InstitutionFormTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final readonly class InstitutionFormTemplateService
{
    public function __construct(
        private InstitutionReferenceReader $institutions,
        private CustomerOrderReferenceReader $customers,
    ) {}

    /** @return array{path: string, filename: string, form_uuid: string, template_id: int, metadata: array<string, scalar|null>} */
    public function generate(int $institutionId, int $customerId): array
    {
        $institution = $this->institutions->institutionsByIds([$institutionId])[$institutionId] ?? null;
        if ($institution === null) {
            throw new RuntimeException(__('orders.errors.institution_unavailable'));
        }
        $customer = $this->customers->customerForOrder($customerId);
        $template = InstitutionFormTemplate::query()->firstOrCreate(
            [
                'institution_id' => $institutionId,
                'template_key' => InstitutionFormSchema::TEMPLATE_KEY,
                'version' => InstitutionFormSchema::VERSION,
            ],
            [
                'columns' => InstitutionFormSchema::COLUMNS,
                'is_active' => true,
            ],
        );
        if (! $template->is_active) {
            throw new RuntimeException(__('orders.errors.institution_template_inactive'));
        }

        $formUuid = (string) Str::uuid();
        $metadata = [
            'template_key' => InstitutionFormSchema::TEMPLATE_KEY,
            'template_version' => InstitutionFormSchema::VERSION,
            'institution_id' => $institutionId,
            'institution_code' => $institution['code'],
            'customer_id' => $customerId,
            'customer_code' => $customer['code'],
            'customer_name' => $customer['name'],
            'form_uuid' => $formUuid,
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
        ];
        $metadata['signature'] = InstitutionFormSchema::signature($metadata);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('机构回传');
        $sheet->fromArray(InstitutionFormSchema::HEADERS, null, 'A1');
        $arrivedOn = isset($customer['arrived_at'])
            ? CarbonImmutable::parse((string) $customer['arrived_at'])->startOfDay()->toDateTimeImmutable()
            : null;
        $sheet->fromArray([
            $customer['name'],
            $arrivedOn,
            null,
            1,
            null,
            null,
        ], null, 'A2');
        $sheet->freezePane('A2');
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('B2:B100')->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        $sheet->getStyle('D2:D100')->getNumberFormat()->setFormatCode('#,##0.###');
        $sheet->getStyle('E2:E100')->getNumberFormat()->setFormatCode('#,##0');
        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setWidth(18);
        }
        $sheet->getColumnDimension('F')->setWidth(30);

        $metaSheet = $spreadsheet->createSheet();
        $metaSheet->setTitle('__GN_META');
        $metaSheet->fromArray([
            ['key', 'value'],
            ...array_map(static fn (string $key, mixed $value): array => [$key, (string) $value], array_keys($metadata), array_values($metadata)),
        ], null, 'A1');
        $metaSheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        $path = tempnam(storage_path('app'), 'gn-institution-form-');
        if ($path === false) {
            throw new RuntimeException('无法创建机构表单临时文件。');
        }
        (new Xlsx($spreadsheet))->save($path);

        return [
            'path' => $path,
            'filename' => sprintf('%s-%s-机构回传表-v%d.xlsx', $institution['code'], $customer['code'], InstitutionFormSchema::VERSION),
            'form_uuid' => $formUuid,
            'template_id' => (int) $template->id,
            'metadata' => $metadata,
        ];
    }
}
