<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataImport\Application\Services\SpreadsheetImportParser;
use App\Modules\DataImport\Domain\ImportBatchStatus;
use App\Modules\DataImport\Domain\ImportProfile;
use App\Modules\DataImport\Domain\ImportRowStatus;
use App\Modules\DataImport\Infrastructure\EncryptedImportStorage;
use App\Modules\DataImport\Infrastructure\Models\ImportBatch;
use App\Modules\DataImport\Infrastructure\Models\ImportFile;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpreadsheetImportParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recalculates_details_and_reports_summary_differences(): void
    {
        Storage::fake('local');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->superAdmin()->withTwoFactor()->create();
        $batch = ImportBatch::query()->create([
            'created_by' => $user->id,
            'status' => ImportBatchStatus::Uploaded,
        ]);

        $agents = <<<'CSV'
代理商编号,代理商名称,代理类型,联系人,联系方式,代理等级,等级体系,等级起始月,合作开始月份,合作状态,备注,合同编号,合同有效期
SZ-JG,神州国际旅行社,旅行社,张女士,13800000000,黄金代理商,代理商合作政策,2026-07,2026-07,合作中,,LY-1,2026-07 至 2028-07
ZH-GT,张先生（个体户）,个体户,张先生,13900000000,黄金合伙人,高端人脉变现计划,2026-07,2026-07,合作中,,待签,
KR-DY,金导游（在韩合伙人）,地陪,金先生,01011112222,在韩合伙人,在韩合伙人政策,2026-07,2026-07,合作中,,,
KR-XY,李同学（在韩合伙人）,留学生,李同学,01022223333,在韩合伙人,在韩合伙人政策,2026-07,2026-07,合作中,,,
KR-BJ,朴社长（釜山·在韩合伙人）,旅行社,朴社长,01033334444,在韩合伙人,在韩合伙人政策,2026-07,2026-07,合作中,,,
CSV;

        $details = <<<'CSV'
代理商名称,客户编号,姓名,联系方式,意向机构,项目,预约到院,消费金额（KRW 韩币）,推广费比例,推广费金额（KRW 韩币）,翻译负责人,负责人,备注
神州国际旅行社,SZ-JG-0001,王女士,13800138000,dod 皮肤科,抗衰,2026-07-25,"3,500,000",12%,"420,000",金老师,小李,
神州国际旅行社,SZ-JG-0002,陈女士,13800138001,dod 皮肤科,干细胞,2026-07-28,"5,000,000",12%,"600,000",金老师,小李,
神州国际旅行社,SZ-JG-0003,赵先生,13800138002,Blanche 齿科,牙贴片,2026-07-30,"2,000,000",6%,"120,000",朴老师,小王,
神州国际旅行社,SZ-JG-0004,周女士,13800138003,Graycity 纹绣,眉眼唇,2026-07-31,"1,500,000",14%,"210,000",朴老师,小王,
张先生（个体户）,ZH-GT-0001,李女士,13900139000,Blanche 齿科,牙贴片,2026-07-30,"1,500,000",4%,"60,000",朴老师,小王,
KR-DY,KR-DY-0001,朴女士,01011112222,dod 皮肤科,抗衰,2026-07-26,"4,200,000",15%,"630,000",,小李,
KR-XY,KR-XY-0001,崔先生,01022223333,Blanche 齿科,美牙贴片,2026-07-27,"6,000,000",10%,"600,000",,小王,
KR-BJ,KR-BJ-0001,金先生,01033334444,Graycity 纹绣,头皮纹发,2026-07-28,"2,000,000",15%,"300,000",,小王,
CSV;

        $summaryUtf8 = <<<'CSV'
代理商编号,代理商名称,代理商等级,月客户总数,消费总额（KRW),推广费总额（KRW),结算日期,结算汇率,推广费总额（RMB 元）,结算状态,备注
SZ-JG,神州国际旅行社,黄金代理商,4,"12,000,000","1,350,000",2026/8/5,224,"6,027",已对账,备注中的数值不参与计算
ZH-GT,张先生（个体户）,黄金合伙人,1,"1,500,000","60,000",2026/8/5,224,268,已结清,
KR-DY,金导游（在韩合伙人）,在韩合伙人,0,0,0,,0,0,,
KR-XY,李同学（在韩合伙人）,在韩合伙人,0,0,0,,0,0,,
KR-BJ,朴社长（釜山·在韩合伙人）,在韩合伙人,0,0,0,,0,0,,
CSV;

        $this->attach($batch, '代理商档案.csv', $agents);
        $this->attach($batch, '代理商月明细.csv', $details);
        $this->attach($batch, '代理商月结汇总.csv', mb_convert_encoding($summaryUtf8, 'GB18030', 'UTF-8'));

        app(SpreadsheetImportParser::class)->parse($batch->fresh('files'));
        $batch->refresh();

        $this->assertSame(ImportBatchStatus::NeedsReview, $batch->status);
        $this->assertSame(18, $batch->total_rows);
        $this->assertSame(15, $batch->valid_rows);
        $this->assertSame(3, $batch->error_rows);

        $this->assertDatabaseHas('import_rows', [
            'profile' => ImportProfile::MonthlyDetail->value,
            'status' => ImportRowStatus::Valid->value,
        ]);

        $sz = $batch->rows()
            ->where('profile', ImportProfile::SettlementSummary)
            ->whereJsonContains('normalized_data->agent_code', 'SZ-JG')
            ->firstOrFail();
        $this->assertSame(1350000, $sz->normalized_data['commission_krw']);
        $this->assertSame(602700, $sz->normalized_data['payout_cny_fen']);

        $koreanErrors = $batch->rows()
            ->where('profile', ImportProfile::SettlementSummary)
            ->where('status', ImportRowStatus::Error)
            ->count();
        $this->assertSame(3, $koreanErrors);
    }

    private function attach(ImportBatch $batch, string $name, string $contents): void
    {
        $upload = UploadedFile::fake()->createWithContent($name, $contents);
        $stored = app(EncryptedImportStorage::class)->store($batch->id, $upload);

        ImportFile::query()->create([
            'import_batch_id' => $batch->id,
            'original_name' => $name,
            'extension' => 'csv',
            'mime_type' => 'text/csv',
            'size_bytes' => $stored['size'],
            'sha256' => $stored['sha256'],
            'encrypted_path' => $stored['path'],
            'status' => 'uploaded',
        ]);
    }
}
