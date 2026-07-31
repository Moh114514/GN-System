<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Models\User;
use App\Modules\Report\Application\Services\GlobalSearch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('全部搜索结果')]
class GlobalSearchPage extends Component
{
    public string $q = '';

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'q' => ['except' => ''],
    ];

    public function render(GlobalSearch $search): View
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $query = trim($this->q);

        return view('livewire.reports.global-search', [
            'query' => $query,
            'results' => $search->search($query, $user->is_super_admin),
        ]);
    }
}
