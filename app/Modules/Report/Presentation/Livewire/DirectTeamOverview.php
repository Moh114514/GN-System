<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Modules\Report\Application\Services\DirectTeamOverviewService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DirectTeamOverview extends Component
{
    public string $month = '';

    public string $ownerId = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    /** @var array<string, array<string, string>> */
    protected array $queryString = [
        'month' => ['except' => ''],
        'ownerId' => ['except' => ''],
    ];

    public function mount(DirectTeamOverviewService $overview): void
    {
        if ($this->month === '') {
            $this->month = $overview->currentMonth();
        }
        $this->loadSnapshot($overview);
    }

    public function updated(string $property, DirectTeamOverviewService $overview): void
    {
        if (! in_array($property, ['month', 'ownerId'], true)) {
            return;
        }

        try {
            $this->ownerId = trim($this->ownerId);
            if ($this->ownerId !== '' && preg_match('/^\d+$/D', $this->ownerId) !== 1) {
                abort(404);
            }
            $this->loadSnapshot($overview);
            $this->resetValidation();
        } catch (\DomainException $exception) {
            $this->addError($property, $exception->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.reports.direct-team-overview')->title(__('direct_team.title'));
    }

    private function loadSnapshot(DirectTeamOverviewService $overview): void
    {
        $this->snapshot = $overview->snapshot(
            $this->month,
            $this->ownerId === '' ? null : (int) $this->ownerId,
        );
    }
}
