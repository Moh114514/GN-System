<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use DomainException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DashboardExportGenerator
{
    private const PDF_CACHE_PATH = 'framework/cache/dompdf';

    public function __construct(
        private readonly DashboardSnapshotPresenter $presenter,
        private readonly AccessContextResolver $access,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public function generate(User $user, string $format, array $snapshot): ReportExport
    {
        $context = $this->access->forUser($user);
        abort_unless(! $context->isCustomerService() && $context->hasEffectiveBusinessScope(), 403);
        if (in_array($format, ['pdf', 'html'], true) === false) {
            throw new DomainException(__('dashboard.errors.export_format'));
        }
        $locale = SupportedLocale::fromCandidate($snapshot['locale'] ?? app()->getLocale()) ?? SupportedLocale::default();
        $snapshot = [...$snapshot, 'locale' => $locale->value, '_permission_fingerprint' => $context->fingerprint];
        $previousLocale = app()->getLocale();
        app()->setLocale($locale->value);
        try {
            $snapshot = $this->presenter->present($snapshot);
        } finally {
            app()->setLocale($previousLocale);
        }
        $reusableExport = $this->reusableExport($user, $format, $snapshot);
        if ($reusableExport !== null) {
            return $reusableExport;
        }
        $pdfRegularFontPath = $format === 'pdf' ? (string) config('reporting.pdf.font_regular_path') : null;
        $pdfBoldFontPath = $format === 'pdf' ? (string) config('reporting.pdf.font_bold_path') : null;
        if ($pdfRegularFontPath !== null && (! is_readable($pdfRegularFontPath) || ! is_readable((string) $pdfBoldFontPath))) {
            throw new RuntimeException(__('dashboard.errors.pdf_font_missing'));
        }
        $export = ReportExport::query()->create([
            'created_by' => $user->id,
            'kind' => 'dashboard',
            'format' => $format,
            'status' => 'generating',
            'criteria_snapshot' => [...($snapshot['range'] ?? []), 'locale' => $locale->value],
            'data_snapshot' => $snapshot,
            'expires_at' => now()->addHours(24),
        ]);
        $previousLocale = app()->getLocale();
        app()->setLocale($locale->value);
        try {
            $html = view('reports.dashboard-export', [
                'snapshot' => $snapshot,
                'pdfRegularFontPath' => $pdfRegularFontPath,
                'pdfBoldFontPath' => $pdfBoldFontPath,
            ])->render();
        } finally {
            app()->setLocale($previousLocale);
        }
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
                throw new RuntimeException(__('dashboard.errors.pdf_cache_unwritable'));
            }
            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setChroot([base_path(), dirname((string) $pdfRegularFontPath), dirname((string) $pdfBoldFontPath)]);
            $options->setDefaultFont('GN System Sans');
            $options->setIsFontSubsettingEnabled(false);
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

    /** @param array<string, mixed> $snapshot */
    private function reusableExport(User $user, string $format, array $snapshot): ?ReportExport
    {
        $exports = ReportExport::query()
            ->where('created_by', $user->id)
            ->where('kind', 'dashboard')
            ->where('format', $format)
            ->where('status', 'completed')
            ->where('expires_at', '>', now())
            ->latest('generated_at')
            ->limit(20)
            ->get();

        foreach ($exports as $export) {
            if (
                // PostgreSQL jsonb normalizes equivalent numeric values such
                // as 0.0 and 0 when the stored snapshot is read back.
                $export->data_snapshot == $snapshot
                && is_string($export->path)
                && Storage::disk('local')->exists($export->path)
            ) {
                return $export;
            }
        }

        return null;
    }
}
