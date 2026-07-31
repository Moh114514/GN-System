<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Modules\Config\Application\Services\ConfigurationHistoryCoordinator;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('配置历史与回滚')]
class ConfigurationHistory extends Component
{
    public ?string $selectedOwner = null;

    public ?int $selectedSnapshotId = null;

    /** @var array<string, array{changed: bool, target_count: int, current_count: int, target: array<int, array<string, mixed>>, current: array<int, array<string, mixed>>}> */
    public array $diff = [];

    public function showDiff(string $owner, int $snapshotId, ConfigurationHistoryCoordinator $history): void
    {
        $this->selectedOwner = $owner;
        $this->selectedSnapshotId = $snapshotId;
        $this->diff = $history->diff($owner, $snapshotId);
    }

    public function rollback(string $owner, int $snapshotId, ConfigurationHistoryCoordinator $history): void
    {
        try {
            $history->rollback($owner, $snapshotId, (int) Auth::id(), request()->ip());
            $this->showDiff($owner, $snapshotId, $history);
            session()->flash('status', '配置已在单一事务中回滚，并生成新的回滚记录。');
        } catch (DomainException $exception) {
            $this->addError('rollback', $exception->getMessage());
        }
    }

    public function render(ConfigurationHistoryCoordinator $history): View
    {
        return view('livewire.configuration.configuration-history', ['history' => $history->history()]);
    }
}
