<?php

namespace App\Modules\DataImport\Application\Services;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

final readonly class SpreadsheetCellValueReader
{
    /**
     * @return array{raw_value: mixed, formatted_value: string, normalized_value: mixed, cell_data_type: string, number_format: string}
     */
    public function read(Cell $cell, bool $dateField = false): array
    {
        $rawValue = $cell->getValue();
        $formattedValue = $cell->getFormattedValue();

        return [
            'raw_value' => $this->serializableValue($rawValue),
            'formatted_value' => $formattedValue,
            'normalized_value' => $this->normalizedValue($cell, $rawValue, $formattedValue, $dateField),
            'cell_data_type' => $cell->getDataType(),
            'number_format' => (string) $cell->getStyle()->getNumberFormat()->getFormatCode(),
        ];
    }

    private function normalizedValue(Cell $cell, mixed $rawValue, string $formattedValue, bool $dateField): mixed
    {
        if (! $dateField) {
            return $formattedValue;
        }

        if ($rawValue instanceof DateTimeInterface) {
            return $rawValue->format('Y-m-d');
        }

        if (is_numeric($rawValue) && $cell->getStyle()->getNumberFormat()->getFormatCode() !== null
            && ExcelDate::isDateTime($cell)) {
            return ExcelDate::excelToDateTimeObject((float) $rawValue)->format('Y-m-d');
        }

        return $formattedValue;
    }

    private function serializableValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
    }
}
