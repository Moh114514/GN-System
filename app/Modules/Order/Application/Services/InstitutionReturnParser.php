<?php

namespace App\Modules\Order\Application\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

final readonly class InstitutionReturnParser
{
    /**
     * @param  array{institution_id: int, customer_id: int, customer_code: string, customer_name: string}  $expected
     * @return array{
     *     metadata: array<string, string>,
     *     integrity_signature: string,
     *     form_uuid: string,
     *     institution_id: int,
     *     customer_id: int,
     *     occurred_on: CarbonImmutable,
     *     items: array<int, array{project_name: string, specification: string|null, quantity: string, unit_price_krw: int, amount_krw: int, notes: string|null}>,
     *     total_amount_krw: int
     * }
     */
    public function parse(string $contents, string $extension, array $expected): array
    {
        $extension = strtolower(trim($extension));
        if (! in_array($extension, ['xlsx', 'xlsm', 'xls'], true)) {
            throw new DomainException(__('orders.errors.institution_form_extension'));
        }

        $path = tempnam(sys_get_temp_dir(), 'gn-institution-return-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new DomainException(__('orders.errors.institution_form_unreadable'));
        }

        try {
            $spreadsheet = IOFactory::load($path);
            $metaSheet = $spreadsheet->getSheetByName('__GN_META');
            if ($metaSheet === null) {
                throw new DomainException(__('orders.errors.institution_form_metadata_missing'));
            }

            $metadata = [];
            for ($row = 2; $row <= $metaSheet->getHighestRow(); $row++) {
                $key = trim((string) $metaSheet->getCell("A{$row}")->getValue());
                if ($key !== '') {
                    $metadata[$key] = trim((string) $metaSheet->getCell("B{$row}")->getValue());
                }
            }
            $signature = (string) ($metadata['signature'] ?? '');
            unset($metadata['signature']);
            if ($signature === '' || ! hash_equals(InstitutionFormSchema::signature($metadata), $signature)) {
                throw new DomainException(__('orders.errors.institution_form_signature_invalid'));
            }
            $this->assertMetadata($metadata, $expected);

            $sheet = $spreadsheet->getSheetByName('机构回传');
            if ($sheet === null) {
                throw new DomainException(__('orders.errors.institution_form_sheet_missing'));
            }
            foreach (InstitutionFormSchema::HEADERS as $index => $header) {
                $column = $this->column($index + 1);
                if (trim((string) $sheet->getCell("{$column}1")->getValue()) !== $header) {
                    throw new DomainException(__('orders.errors.institution_form_headers_invalid'));
                }
            }

            $items = [];
            $occurredOn = null;
            $highestRow = $sheet->getHighestDataRow();
            for ($row = 2; $row <= $highestRow; $row++) {
                $values = [];
                foreach (range(1, count(InstitutionFormSchema::COLUMNS)) as $index) {
                    $values[] = $sheet->getCell($this->column($index).$row)->getValue();
                }
                if ($this->rowIsEmpty($values)) {
                    continue;
                }

                if (trim((string) $values[0]) !== $expected['customer_code']
                    || trim((string) $values[1]) !== $expected['customer_name']) {
                    throw new DomainException(__('orders.errors.institution_form_customer_mismatch'));
                }
                $date = $this->date($values[2]);
                if ($date === null) {
                    throw new DomainException(__('orders.errors.institution_form_date_required'));
                }
                if ($occurredOn !== null && ! $occurredOn->isSameDay($date)) {
                    throw new DomainException(__('orders.errors.institution_form_multiple_dates'));
                }
                $occurredOn = $date;

                $project = trim((string) $values[3]);
                if ($project === '') {
                    throw new DomainException(__('orders.errors.institution_form_project_required'));
                }
                $quantity = $this->decimal($values[5], 'quantity');
                if ((float) $quantity <= 0) {
                    throw new DomainException(__('orders.errors.institution_form_quantity_invalid'));
                }
                $unitPrice = $this->amount($values[6], 'unit_price');
                $amount = $this->amount($values[7], 'amount');
                $expectedAmount = (int) round((float) $quantity * $unitPrice);
                if ($expectedAmount !== $amount) {
                    throw new DomainException(__('orders.errors.institution_form_amount_mismatch'));
                }

                $items[] = [
                    'project_name' => $project,
                    'specification' => $this->nullableText($values[4]),
                    'quantity' => $quantity,
                    'unit_price_krw' => $unitPrice,
                    'amount_krw' => $amount,
                    'notes' => $this->nullableText($values[8]),
                ];
            }

            if ($items === [] || $occurredOn === null) {
                throw new DomainException(__('orders.errors.institution_form_empty'));
            }

            return [
                'metadata' => $metadata,
                'integrity_signature' => $signature,
                'form_uuid' => (string) $metadata['form_uuid'],
                'institution_id' => (int) $metadata['institution_id'],
                'customer_id' => (int) $metadata['customer_id'],
                'occurred_on' => $occurredOn,
                'items' => $items,
                'total_amount_krw' => array_sum(array_column($items, 'amount_krw')),
            ];
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DomainException(__('orders.errors.institution_form_unreadable'), previous: $exception);
        } finally {
            @unlink($path);
        }
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  array{institution_id: int, customer_id: int, customer_code: string, customer_name: string}  $expected
     */
    private function assertMetadata(array $metadata, array $expected): void
    {
        if (($metadata['template_key'] ?? '') !== InstitutionFormSchema::TEMPLATE_KEY
            || (int) ($metadata['template_version'] ?? 0) !== InstitutionFormSchema::VERSION
            || (int) ($metadata['institution_id'] ?? 0) !== $expected['institution_id']
            || (int) ($metadata['customer_id'] ?? 0) !== $expected['customer_id']
            || ($metadata['customer_code'] ?? '') !== $expected['customer_code']
            || ($metadata['customer_name'] ?? '') !== $expected['customer_name']) {
            throw new DomainException(__('orders.errors.institution_form_metadata_mismatch'));
        }
        if (! isset($metadata['form_uuid']) || ! Str::isUuid($metadata['form_uuid'])) {
            throw new DomainException(__('orders.errors.institution_form_uuid_invalid'));
        }
    }

    /** @param array<int, mixed> $values */
    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if ($value instanceof DateTimeInterface || trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }
        if (is_numeric($value) && (float) $value > 0) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay();
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $text = str_replace(['年', '月', '日', '/', '.'], ['-', '-', '', '-', '-'], $text);
        foreach (['Y-m-d', 'Y-n-j', 'm-d-Y', 'n-j-Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:s'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $text);
            } catch (Throwable) {
                continue;
            }
            if ($date instanceof CarbonImmutable && $date->format($format) === $text) {
                return $date->startOfDay();
            }
        }

        try {
            return CarbonImmutable::parse($text)->startOfDay();
        } catch (Throwable) {
            throw new DomainException(__('orders.errors.institution_form_date_invalid'));
        }
    }

    private function decimal(mixed $value, string $field): string
    {
        $text = str_replace(',', '', trim((string) $value));
        if ($text === '' || ! is_numeric($text)) {
            throw new DomainException(__('orders.errors.institution_form_number_invalid', ['field' => $field]));
        }

        return number_format((float) $text, 3, '.', '');
    }

    private function amount(mixed $value, string $field): int
    {
        $text = str_replace([',', '₩', 'KRW', ' '], '', trim((string) $value));
        if ($text === '' || ! is_numeric($text) || (float) $text < 0 || floor((float) $text) !== (float) $text) {
            throw new DomainException(__('orders.errors.institution_form_number_invalid', ['field' => $field]));
        }

        return (int) $text;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function column(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)).$column;
            $number = intdiv($number, 26);
        }

        return $column;
    }
}
