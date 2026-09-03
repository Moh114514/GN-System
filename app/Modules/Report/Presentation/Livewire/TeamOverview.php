<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Modules\Report\Application\Services\TeamOverviewService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TeamOverview extends Component
{
    public string $groupId = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'groupId' => ['except' => ''],
    ];

    public function mount(TeamOverviewService $overview): void
    {
        $this->loadSnapshot($overview);
    }

    public function updatedGroupId(TeamOverviewService $overview): void
    {
        $this->loadSnapshot($overview);
    }

    public function render(): View
    {
        return view('livewire.reports.team-overview')->title(__('team.title'));
    }

    private function loadSnapshot(TeamOverviewService $overview): void
    {
        if ($this->groupId !== '' && preg_match('/^\d+$/D', $this->groupId) !== 1) {
            abort(404);
        }

        $this->snapshot = $overview->snapshot($this->groupId === '' ? null : (int) $this->groupId);
    }
}
