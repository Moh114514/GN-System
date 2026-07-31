<?php

namespace App\Modules\Report\Application\Services;

use App\Models\User;
use App\Modules\Report\Infrastructure\Models\SavedQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SavedQueryManager
{
    public function __construct(private ReportSearch $search) {}

    /** @return Collection<int, SavedQuery> */
    public function visibleTo(User $user): Collection
    {
        return SavedQuery::query()
            ->where(fn ($query) => $query
                ->where('scope', 'team')
                ->orWhere('created_by', $user->id))
            ->orderBy('scope')
            ->orderBy('name')
            ->get();
    }

    /** @param array<string, int|string|null> $criteria */
    public function save(User $user, string $name, string $scope, array $criteria): SavedQuery
    {
        return SavedQuery::query()->create([
            'created_by' => $user->id,
            'name' => trim($name),
            'scope' => $scope === 'team' ? 'team' : 'personal',
            'criteria' => $this->search->queryData($criteria)->toArray(),
            'sort_field' => $criteria['sort_field'] ?? 'completed_at',
            'sort_direction' => ($criteria['sort_direction'] ?? null) === 'asc' ? 'asc' : 'desc',
        ]);
    }

    /** @param array<string, int|string|null> $criteria */
    public function update(User $user, int $id, string $name, string $scope, array $criteria): void
    {
        $saved = SavedQuery::query()->findOrFail($id);
        $this->authorizeMaintenance($user, $saved);
        $saved->update([
            'name' => trim($name),
            'scope' => $scope === 'team' ? 'team' : 'personal',
            'criteria' => $this->search->queryData($criteria)->toArray(),
            'sort_field' => $criteria['sort_field'] ?? 'completed_at',
            'sort_direction' => ($criteria['sort_direction'] ?? null) === 'asc' ? 'asc' : 'desc',
        ]);
    }

    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $saved = SavedQuery::query()->lockForUpdate()->findOrFail($id);
            $this->authorizeMaintenance($user, $saved);
            $saved->delete();
        });
    }

    private function authorizeMaintenance(User $user, SavedQuery $saved): void
    {
        abort_unless(
            (int) $saved->created_by === (int) $user->id
            || ($saved->scope === 'team' && $user->is_super_admin),
            403,
        );
    }
}
