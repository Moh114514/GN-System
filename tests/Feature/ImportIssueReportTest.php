<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\ImportIssueRecorder;
use App\Modules\DataImport\Application\Services\ImportIssueReportGenerator;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
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

        $this->assertSame('阶段', $sheet->getCell('A1')->getValue());
        $this->assertSame('=HYPERLINK("https://example.test")', $sheet->getCell('L2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('L2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());

        $workbook->disconnectWorksheets();
        unlink($absolutePath);
    }

    public function test_report_headers_and_fixed_labels_follow_the_current_locale(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
        ]);
        ImportIssue::query()->create([
            'import_batch_id' => $batch->id,
            'stage' => 'relation_validation',
            'severity' => 'warning',
            'code' => 'relation_unresolved',
            'profile' => 'customer_followup',
            'message' => 'raw diagnostic must remain unchanged',
            'message_key' => 'imports.errors.relation_unresolved',
        ]);
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            $path = app(ImportIssueReportGenerator::class)->generate($batch);
            $workbook = IOFactory::load(Storage::disk('local')->path($path));
            $sheet = $workbook->getActiveSheet();
            $this->assertSame('단계', $sheet->getCell('A1')->getValue());
            $this->assertSame('관계 검증', $sheet->getCell('A2')->getValue());
            $this->assertSame('경고', $sheet->getCell('B2')->getValue());
            $this->assertSame('고객 후속 관리', $sheet->getCell('G2')->getValue());
            $this->assertSame(__('imports.errors.relation_unresolved'), $sheet->getCell('N2')->getValue());
            $workbook->disconnectWorksheets();
            unlink(Storage::disk('local')->path($path));
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_dynamic_issue_message_is_stored_with_named_parameters(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::NeedsReview,
        ]);

        app(ImportIssueRecorder::class)->record(
            $batch,
            'relation_validation',
            'error',
            'relation_unresolved',
            '机构代码“MISSING”不存在。',
        );

        $issue = ImportIssue::query()->sole();
        $this->assertSame('imports.errors.institution_code_missing', $issue->message_key);
        $this->assertSame(['institution_code' => 'MISSING'], $issue->message_parameters);
    }
}
