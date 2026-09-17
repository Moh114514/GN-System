<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Illuminate\Database\Eloquent\Builder;

final readonly class SettlementReadScope
{
    public function __construct(private AccessContextResolver $access) {}

    public function isAdmin(): bool
    {
        return $this->access->current()->isSuperAdmin();
    }

    /** @return list<int> */
    public function agentIds(): array
    {
        return $this->access->current()->agentIds;
    }

    /** @return Builder<Settlement> */
    public function visibleQuery(): Builder
    {
        $context = $this->access->current();

        return Settlement::query()->when(
            ! $context->isSuperAdmin(),
            fn ($query) => $query->whereIn('agent_id', $context->agentIds),
        );
    }

    public function assertAdmin(): void
    {
        abort_unless($this->isAdmin(), 403);
    }

    public function assertVisible(Settlement $settlement): void
    {
        abort_unless($this->isAdmin() || in_array((int) $settlement->agent_id, $this->agentIds(), true), 403);
    }
}
