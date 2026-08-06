<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\ImportIssueReportGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ImportIssueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_issue_rows_and_keeps_formula_like_values_as_strings(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'stage' => 'field_validation',
            'severity' => 'error',
            'code' => 'field_validation_failed',
            'message' => 'formula-like values must stay text',
            'context_encrypted' => [
                'file' => 'input.csv',
                'raw_value' => '=HYPERLINK("https://example.test")',
                'normalized_value' => '+123',
            ],
        ]);

        $path = app(ImportIssueReportGenerator::class)->generate($batch);
        $absolutePath = Storage::disk('local')->path($path);
        $workbook = IOFactory::load($absolutePath);
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('stage', $sheet->getCell('A1')->getValue());
        $this->assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('L2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('L2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());

        $workbook->disconnectWorksheets();
        unlink($absolutePath);
    }
}
