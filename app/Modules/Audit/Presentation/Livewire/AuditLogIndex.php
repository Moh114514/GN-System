<?php

namespace App\Modules\Audit\Presentation\Livewire;

use App\Modules\Audit\Application\Contracts\AuditLogReader;
use App\Modules\Audit\Application\Data\AuditLogFilterData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class AuditLogIndex extends Component
{
    use WithPagination;

    public string $occurredOn = '';

    public string $causerId = '';

    public string $targetUserId = '';

    public string $module = '';

    public string $action = '';

    public int $perPage = 20;

    /** @var array<string, string|int|array{except: string|int}> */
    protected array $queryString = [
        'occurredOn' => ['except' => ''],
        'causerId' => ['except' => ''],
        'targetUserId' => ['except' => ''],
        'module' => ['except' => ''],
        'action' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function updated(string $property): void
    {
        if (in_array($property, ['occurredOn', 'causerId', 'targetUserId', 'module', 'action', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('occurredOn', 'causerId', 'targetUserId', 'module', 'action');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(AuditLogReader $reader): View
    {
        $options = $reader->filterOptions();
        $entries = $reader->paginate(new AuditLogFilterData(
            occurredOn: $this->occurredOn === '' ? null : $this->occurredOn,
            causerId: $this->causerId === '' ? null : (int) $this->causerId,
            targetUserId: $this->targetUserId === '' ? null : (int) $this->targetUserId,
            module: $this->module === '' ? null : $this->module,
            action: $this->action === '' ? null : $this->action,
        ), in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20);

        return view('livewire.audit.audit-log-index', compact('entries', 'options'))
            ->title(__('audit.index.title'));
    }
}
