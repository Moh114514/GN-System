<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Report\Application\Services\TeamOverviewService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TeamOverview extends Component
{
    public string $groupId = '';

    public string $month = '';

    public string $bdManagerId = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'groupId' => ['except' => ''],
        'month' => ['except' => ''],
        'bdManagerId' => ['except' => ''],
    ];

    public function mount(TeamOverviewService $overview, BusinessClock $clock): void
    {
        $this->month = $this->month !== '' ? $this->month : $clock->now()->format('Y-m');
        $this->loadSnapshot($overview);
    }

    public function updatedGroupId(TeamOverviewService $overview): void
    {
        $this->loadSnapshot($overview);
    }

    public function updatedMonth(TeamOverviewService $overview): void
    {
        $this->loadSnapshot($overview);
    }

    public function updatedBdManagerId(TeamOverviewService $overview): void
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

        if ($this->bdManagerId !== '' && preg_match('/^\d+$/D', $this->bdManagerId) !== 1) {
            abort(404);
        }

        $this->snapshot = $overview->snapshot(
            $this->groupId === '' ? null : (int) $this->groupId,
            $this->month,
            $this->bdManagerId === '' ? null : (int) $this->bdManagerId,
        );
    }
}
