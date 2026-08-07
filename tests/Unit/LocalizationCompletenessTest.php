<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalizationCompletenessTest extends TestCase
{
    public function test_chinese_and_korean_catalogs_have_identical_files_and_keys(): void
    {
        $chineseFiles = collect(File::files(lang_path('zh_CN')))->map->getFilename()->sort()->values()->all();
        $koreanFiles = collect(File::files(lang_path('ko_KR')))->map->getFilename()->sort()->values()->all();
        $this->assertSame($chineseFiles, $koreanFiles);

        foreach ($chineseFiles as $filename) {
            $chinese = require lang_path('zh_CN/'.$filename);
            $korean = require lang_path('ko_KR/'.$filename);
            $this->assertIsArray($chinese);
            $this->assertIsArray($korean);
            $this->assertSame(
                $this->flattenedKeys($chinese),
                $this->flattenedKeys($korean),
                "Translation key mismatch in {$filename}",
            );
        }
    }

    #[DataProvider('userFacingSourceDirectories')]
    public function test_user_facing_sources_do_not_contain_fixed_chinese_copy(string $directory): void
    {
        foreach (File::allFiles(base_path($directory)) as $file) {
            if (! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }
            if ($directory === 'app/Modules' && ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Presentation'.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $contents = File::get($file->getPathname());
            $contents = str_replace([
                '光年拾捌 Lightyear 18',
                'value="女"',
                'value="男"',
                'value="其他"',
            ], '', $contents);

            $this->assertDoesNotMatchRegularExpression(
                '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}]/u',
                $contents,
                'Fixed Chinese copy remains in '.$file->getRelativePathname(),
            );
        }
    }

    public function test_runtime_application_boundaries_do_not_embed_fixed_chinese_copy(): void
    {
        foreach ($this->runtimeUserFacingSources() as $relativePath) {
            $path = base_path($relativePath);
            $this->assertFileExists($path);
            $contents = File::get($path);

            $this->assertDoesNotMatchRegularExpression(
                '/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}]/u',
                $contents,
                'Fixed Chinese copy remains in '.$relativePath,
            );
        }
    }

    /**
     * Explicit runtime boundary allowlist. Business data, import compatibility
     * messages, and audit history are intentionally outside this static scan.
     *
     * @return list<string>
     */
    private function runtimeUserFacingSources(): array
    {
        return [
            'app/Modules/Order/Application/Services/OrderManagementWorkspace.php',
            'app/Modules/Reminder/Application/Services/DatabaseOrderReminderReader.php',
            'app/Modules/Reminder/Application/Services/ReminderContentPresenter.php',
            'app/Modules/Reminder/Application/Services/DatabaseTreatmentReminderGateway.php',
            'app/Modules/Report/Application/Services/DashboardExportGenerator.php',
            'app/Modules/Report/Application/Services/ReportSearchExportGenerator.php',
            'app/Modules/Report/Application/Services/DashboardSnapshotPresenter.php',
            'app/Modules/Report/Application/Services/ReportExportFailurePresenter.php',
            'app/Modules/Settlement/Application/Services/SettlementDocumentGenerator.php',
            'app/Modules/Settlement/Application/Services/SettlementRunFailureReportGenerator.php',
            'app/Modules/Reminder/Infrastructure/Notifications/DingTalkClient.php',
            'app/Modules/Auth/Http/Middleware/EnsureUserIsActive.php',
            'app/Modules/Auth/Http/Middleware/RequireTwoFactorForSuperAdmin.php',
        ];
    }

    /** @return array<string, array{string}> */
    public static function userFacingSourceDirectories(): array
    {
        return [
            'module presentation classes' => ['app/Modules'],
            'Blade views' => ['resources/views'],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int, string>
     */
    private function flattenedKeys(array $values, string $prefix = ''): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $keys = [...$keys, ...$this->flattenedKeys($value, $path)];
            } else {
                $keys[] = $path;
            }
        }
        sort($keys);

        return $keys;
    }
}
