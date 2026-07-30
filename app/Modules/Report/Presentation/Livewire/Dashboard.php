<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Models\User;
use App\Modules\Report\Application\Services\DashboardExportGenerator;
use App\Modules\Report\Application\Services\DashboardRangeFactory;
use App\Modules\Report\Application\Services\DashboardService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('数据看板')]
class Dashboard extends Component
{
    public string $preset = 'month';

    public string $customFrom = '';

    public string $customTo = '';

    /** @var array<string, mixed> */
    public array $snapshot = [];

    public ?string $rangeError = null;

    public int $refreshSeconds = 300;

    public function mount(DashboardRangeFactory $ranges, DashboardService $dashboard): void
    {
        $this->refreshSeconds = $dashboard->refreshSeconds();
        $this->loadSnapshot($ranges, $dashboard);
    }

    public function updatedPreset(DashboardRangeFactory $ranges, DashboardService $dashboard): void
    {
        if ($this->preset !== 'custom') {
            $this->loadSnapshot($ranges, $dashboard);
        }
    }

    public function applyCustomRange(DashboardRangeFactory $ranges, DashboardService $dashboard): void
    {
        $this->loadSnapshot($ranges, $dashboard);
    }

    public function refreshDashboard(DashboardRangeFactory $ranges, DashboardService $dashboard): void
    {
        $this->loadSnapshot($ranges, $dashboard, true);
    }

    public function export(
        string $format,
        DashboardExportGenerator $generator,
    ): void {
        abort_if($this->snapshot === [], 422);
        $export = $generator->generate($this->user(), $format, $this->snapshot);

        $this->redirectRoute('reports.exports.download', ['export' => $export]);
    }

    public function render(): View
    {
        return view('livewire.reports.dashboard');
    }

    private function loadSnapshot(
        DashboardRangeFactory $ranges,
        DashboardService $dashboard,
        bool $force = false,
    ): void {
        try {
            $range = $ranges->make($this->preset, $this->customFrom, $this->customTo);
            $this->snapshot = $dashboard->snapshot($range, $force)->toArray();
            $this->rangeError = null;
            $this->dispatch('dashboard-updated');
        } catch (DomainException $exception) {
            $this->rangeError = $exception->getMessage();
        }
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
