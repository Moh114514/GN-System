<?php

namespace App\Modules\Order\Console;

use App\Models\User;
use App\Modules\Order\Application\Services\OrderAttributionSnapshotBackfillService;
use Illuminate\Console\Command;

final class BackfillOrderAttributionSnapshotsCommand extends Command
{
    protected $signature = 'app:backfill-order-attribution-snapshots
        {--apply : Write resolved snapshots and audit each order}
        {--actor= : User ID recorded as the operator when applying}
        {--reason= : Required reason when applying}';

    protected $description = 'Preview or backfill missing historical order attribution snapshots';

    public function __construct(private readonly OrderAttributionSnapshotBackfillService $backfill)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $result = $this->backfill->preview();
        $resolved = $result['resolved'];
        $unresolved = array_map(
            fn (array $row): array => [$row['order_id'], $row['reason']],
            $result['unresolved'],
        );

        $this->info(sprintf('Found %d completed orders without attribution snapshots.', $result['total']));
        $this->info(sprintf('Resolved: %d; unresolved: %d.', count($resolved), count($unresolved)));
        if ($unresolved !== []) {
            $this->table(['order_id', 'reason'], $unresolved);
            $this->error('No snapshots were written because unresolved orders require manual adjudication.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->comment('Preview only. Re-run with --apply --actor=<user_id> --reason="..." to write.');

            return self::SUCCESS;
        }

        $actorId = filter_var($this->option('actor'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = trim((string) $this->option('reason'));
        if (! is_int($actorId) || $reason === '') {
            $this->error('Applying requires a positive --actor user ID and a non-empty --reason.');

            return self::FAILURE;
        }
        if (! User::query()->whereKey($actorId)->where('is_super_admin', true)->where('is_active', true)->exists()) {
            $this->error('The --actor must be an active super administrator.');

            return self::FAILURE;
        }

        $this->backfill->apply($resolved, $actorId, $reason);

        $this->info(sprintf('Backfilled and audited %d order attribution snapshots.', count($resolved)));

        return self::SUCCESS;
    }
}
