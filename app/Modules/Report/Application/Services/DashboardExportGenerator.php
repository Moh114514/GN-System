<?php

namespace App\Modules\Report\Application\Services;

use App\Models\User;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use DomainException;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;

final class DashboardExportGenerator
{
    /** @param array<string, mixed> $snapshot */
    public function generate(User $user, string $format, array $snapshot): ReportExport
    {
        if (in_array($format, ['pdf', 'html'], true) === false) {
            throw new DomainException('看板服务端导出仅支持 PDF 和 HTML。');
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
        $html = view('reports.dashboard-export', ['snapshot' => $snapshot])->render();
        $directory = "reports/dashboard/{$user->id}";
        Storage::disk('local')->makeDirectory($directory);
        $path = "{$directory}/{$export->id}.{$format}";
        if ($format === 'html') {
            Storage::disk('local')->put($path, $html);
        } else {
            $pdf = new Dompdf(['isRemoteEnabled' => false]);
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
