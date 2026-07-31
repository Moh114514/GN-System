<?php

namespace App\Modules\Report\Application\Services;

use App\Models\User;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DashboardExportGenerator
{
    private const PDF_FONT_PATH = '/usr/share/fonts/truetype/droid/DroidSansFallbackFull.ttf';

    private const PDF_CACHE_PATH = 'framework/cache/dompdf';

    /** @param array<string, mixed> $snapshot */
    public function generate(User $user, string $format, array $snapshot): ReportExport
    {
        if (in_array($format, ['pdf', 'html'], true) === false) {
            throw new DomainException('看板服务端导出仅支持 PDF 和 HTML。');
        }
        $pdfFontPath = $format === 'pdf' ? self::PDF_FONT_PATH : null;
        if ($pdfFontPath !== null && ! is_readable($pdfFontPath)) {
            throw new RuntimeException('看板 PDF 中文字体不可用，请重新构建应用镜像后重试。');
        }
        $export = ReportExport::query()->create([
            'created_by' => $user->id,
            'kind' => 'dashboard',
            'format' => $format,
            'status' => 'generating',
            'criteria_snapshot' => $snapshot['range'] ?? [],
            'data_snapshot' => $snapshot,
            'expires_at' => now()->addHours(24),
        ]);
        $html = view('reports.dashboard-export', [
            'snapshot' => $snapshot,
            'pdfFontPath' => $pdfFontPath,
        ])->render();
        $directory = "reports/dashboard/{$user->id}";
        Storage::disk('local')->makeDirectory($directory);
        $path = "{$directory}/{$export->id}.{$format}";
        if ($format === 'html') {
            Storage::disk('local')->put($path, $html);
        } else {
            $fontCachePath = storage_path(self::PDF_CACHE_PATH.'/fonts');
            $tempPath = storage_path(self::PDF_CACHE_PATH.'/temp');
            File::ensureDirectoryExists($fontCachePath);
            File::ensureDirectoryExists($tempPath);
            if (! is_writable($fontCachePath) || ! is_writable($tempPath)) {
                throw new RuntimeException('看板 PDF 缓存目录不可写，请检查 storage 目录权限后重试。');
            }
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setChroot([base_path(), dirname(self::PDF_FONT_PATH)]);
            $options->setDefaultFont('GN CJK');
            $options->setFontDir($fontCachePath);
            $options->setFontCache($fontCachePath);
            $options->setTempDir($tempPath);
            $pdf = new Dompdf($options);
            $pdf->loadHtml($html, 'UTF-8');
            $pdf->setPaper('A4', 'landscape');
            $pdf->render();
            Storage::disk('local')->put($path, $pdf->output());
        }
        $export->update([
            'status' => 'completed',
            'path' => $path,
            'sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'generated_at' => now(),
        ]);

        return $export;
    }
}
