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
