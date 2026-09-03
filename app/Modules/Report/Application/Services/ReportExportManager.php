<?php

namespace App\Modules\Report\Application\Services;

use App\Infrastructure\Localization\SupportedLocale;
use App\Models\User;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Report\Infrastructure\Models\ReportExport;
use App\Modules\Report\Jobs\GenerateReportExport;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class ReportExportManager
{
    public function __construct(
        private ReportSearch $search,
        private ReportSearchExportGenerator $generator,
        private InstitutionMonthlySalesService $institutionSales,
        private InstitutionMonthlySalesExportGenerator $institutionSalesGenerator,
        private AccessContextResolver $access,
    ) {}

    /** @param array<string, int|string|null> $criteria */
    public function queueSearch(User $user, array $criteria): ReportExport
    {
        $this->assertCanExport();
        $export = $this->createSearchExport($user, $criteria, 'queued');
        GenerateReportExport::dispatch($export->id);

        return $export;
    }

    /** @param array<string, int|string|null> $criteria */
    public function startSearch(User $user, array $criteria): ReportExport
    {
        $this->assertCanExport();
        $total = $this->search->count($criteria);
        $maxRows = max(1, (int) config('reporting.max_export_rows', 50000));

        if ($total > $maxRows) {
            $export = $this->createSearchExport($user, $criteria, 'failed');
            $export->update([
                'failure_reason_key' => 'search.page.exports.failure_reasons.too_many_rows',
                'failure_reason_parameters' => [],
            ]);

            return $export->refresh();
        }

        if ($total > max(0, (int) config('reporting.max_sync_export_rows', 2000))) {
            return $this->queueSearch($user, $criteria);
        }

        return $this->generateSearch($user, $criteria);
    }

    /** @param array<string, int|string|null> $criteria */
    public function generateSearch(User $user, array $criteria): ReportExport
    {
        $this->assertCanExport();
        $export = $this->createSearchExport($user, $criteria, 'generating');

        try {
            return $this->generator->generate($export);
        } catch (Throwable $exception) {
            report($exception);
            $export->update([
                'status' => 'failed',
                'failure_reason_key' => 'search.page.exports.failure_reasons.generation_failed',
                'failure_reason_parameters' => [],
            ]);
        }

        return $export->refresh();
    }

    public function retry(User $user, string $id): void
    {
        $export = ReportExport::query()->where('created_by', $user->id)->findOrFail($id);
        $this->assertCurrentAccess($export);
        abort_unless($export->status === 'failed' && $export->expires_at->isFuture(), 422);
        $export->update([
            'status' => 'queued',
            'failure_reason' => null,
            'failure_reason_key' => null,
            'failure_reason_parameters' => null,
            'path' => null,
            'sha256' => null,
            'generated_at' => null,
        ]);
        GenerateReportExport::dispatch($export->id);
    }

    public function startInstitutionMonthlySales(User $user, string $month, ?int $institutionId = null, string $format = 'xlsx'): ReportExport
    {
        $this->assertCanExport();
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw new DomainException(__('institution_sales.errors.export_format'));
        }
        $context = $this->access->forUser($user);
        [$summary, $institutionName] = $this->access->using(
            $context,
            function () use ($month, $institutionId): array {
                $summary = $this->institutionSales->summary($month, $institutionId);
                $institutionName = $institutionId === null
                    ? null
                    : data_get(collect($this->institutionSales->institutionOptions())->firstWhere('id', $institutionId), 'name');

                return [$summary, $institutionName];
            },
        );
        $export = ReportExport::query()->create([
            'created_by' => $user->id,
            'kind' => 'institution_sales',
            'format' => $format,
            'status' => 'generating',
            'criteria_snapshot' => [
                'month' => $summary->month,
                'institution_id' => $institutionId,
                'institution_name' => $institutionName,
                '_locale' => (SupportedLocale::fromCandidate(app()->getLocale()) ?? SupportedLocale::default())->value,
                '_access' => $context->toSnapshot(),
            ],
            'data_snapshot' => $summary->toArray(),
            'expires_at' => now()->addHours(24),
        ]);

        try {
            return $this->institutionSalesGenerator->generate($export, $summary);
        } catch (Throwable $exception) {
            report($exception);
            $export->update([
                'status' => 'failed',
                'failure_reason_key' => 'institution_sales.errors.generation_failed',
                'failure_reason_parameters' => [],
            ]);
        }

        return $export->refresh();
    }

    /** @return Collection<int, ReportExport> */
    public function recent(User $user): Collection
    {
        return ReportExport::query()
            ->where('created_by', $user->id)
            ->latest()
            ->limit(20)
            ->get();
    }

    /** @param array<string, int|string|null> $criteria */
    private function createSearchExport(User $user, array $criteria, string $status): ReportExport
    {
        $context = $this->access->forUser($user);
        $query = $this->access->using($context, fn (): array => $this->search->queryData($criteria)->toArray());
        $query['_locale'] = (SupportedLocale::fromCandidate(app()->getLocale()) ?? SupportedLocale::default())->value;
        $query['_access'] = $context->toSnapshot();

        return ReportExport::query()->create([
            'created_by' => $user->id,
            'kind' => 'search',
            'format' => 'xlsx',
            'status' => $status,
            'criteria_snapshot' => $query,
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function presentFailure(ReportExport $export): ?string
    {
        return app(ReportExportFailurePresenter::class)->present($export);
    }

    public function assertCurrentAccess(ReportExport $export): void
    {
        $snapshot = $export->criteria_snapshot;
        $snapshot = $snapshot['_access'] ?? null;
        if (! is_array($snapshot)) {
            $snapshot = is_array($export->data_snapshot) ? $export->data_snapshot : [];
        }
        $fingerprint = $snapshot['fingerprint'] ?? $snapshot['_permission_fingerprint'] ?? null;
        abort_unless($fingerprint === null || (is_string($fingerprint) && hash_equals($fingerprint, $this->access->current()->fingerprint)), 403);
    }

    private function assertCanExport(): void
    {
        $context = $this->access->current();
        abort_unless(! $context->isCustomerService() && $context->hasEffectiveBusinessScope(), 403);
    }
}
