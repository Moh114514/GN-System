<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Models\User;
use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Application\Services\ReportSearch;
use App\Modules\Report\Application\Services\SavedQueryManager;
use App\Modules\Report\Infrastructure\Models\SavedQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('多维查询')]
class ReportSearchPage extends Component
{
    public string $completedFrom = '';

    public string $completedTo = '';

    public string $timeFrom = '';

    public string $timeTo = '';

    public string $customerId = '';

    public string $agentId = '';

    public string $projectName = '';

    public string $institutionId = '';

    public string $translatorName = '';

    public string $amountMin = '';

    public string $amountMax = '';

    public string $passport = '';

    public string $sortField = 'completed_at';

    public string $sortDirection = 'desc';

    public int $perPage = 50;

    public int $page = 1;

    public string $savedQueryName = '';

    public string $savedQueryScope = 'personal';

    public ?int $editingSavedQueryId = null;

    /** @var array<string, array<int, array{id: int, name: string}>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'completedFrom' => ['except' => ''],
        'completedTo' => ['except' => ''],
        'timeFrom' => ['except' => ''],
        'timeTo' => ['except' => ''],
        'customerId' => ['except' => ''],
        'agentId' => ['except' => ''],
        'projectName' => ['except' => ''],
        'institutionId' => ['except' => ''],
        'translatorName' => ['except' => ''],
        'amountMin' => ['except' => ''],
        'amountMax' => ['except' => ''],
        'sortField' => ['except' => 'completed_at'],
        'sortDirection' => ['except' => 'desc'],
        'page' => ['except' => 1],
    ];

    public function mount(ReportSearch $search): void
    {
        $this->options = $search->options();
        $this->perPage = $search->defaultPerPage();
    }

    public function updated(string $property): void
    {
        if (! in_array($property, ['page', 'savedQueryName', 'savedQueryScope'], true)) {
            $this->page = 1;
        }
    }

    public function clearFilters(): void
    {
        $this->reset([
            'completedFrom', 'completedTo', 'timeFrom', 'timeTo', 'customerId',
            'agentId', 'projectName', 'institutionId', 'translatorName',
            'amountMin', 'amountMax', 'passport',
        ]);
        $this->sortField = 'completed_at';
        $this->sortDirection = 'desc';
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(int $lastPage): void
    {
        $this->page = min(max(1, $lastPage), $this->page + 1);
    }

    public function saveQuery(SavedQueryManager $manager): void
    {
        $this->validate([
            'savedQueryName' => ['required', 'string', 'max:120'],
            'savedQueryScope' => ['required', 'in:personal,team'],
        ]);
        if ($this->editingSavedQueryId === null) {
            $manager->save($this->user(), $this->savedQueryName, $this->savedQueryScope, $this->criteria());
        } else {
            $manager->update(
                $this->user(),
                $this->editingSavedQueryId,
                $this->savedQueryName,
                $this->savedQueryScope,
                $this->criteria(),
            );
        }
        $this->cancelQueryEdit();
        session()->flash('status', '常用查询已保存。');
    }

    public function loadQuery(int $id): void
    {
        $saved = SavedQuery::query()
            ->where(fn ($query) => $query
                ->where('scope', 'team')
                ->orWhere('created_by', $this->user()->id))
            ->findOrFail($id);
        $this->applySavedQuery($saved);
    }

    public function editQuery(int $id): void
    {
        $saved = SavedQuery::query()
            ->where(fn ($query) => $query
                ->where('scope', 'team')
                ->orWhere('created_by', $this->user()->id))
            ->findOrFail($id);
        abort_unless(
            (int) $saved->created_by === (int) $this->user()->id
            || ($saved->scope === 'team' && $this->user()->is_super_admin),
            403,
        );
        $this->applySavedQuery($saved);
        $this->editingSavedQueryId = (int) $saved->id;
        $this->savedQueryName = (string) $saved->name;
        $this->savedQueryScope = (string) $saved->scope;
    }

    public function cancelQueryEdit(): void
    {
        $this->reset('editingSavedQueryId', 'savedQueryName');
        $this->savedQueryScope = 'personal';
    }

    private function applySavedQuery(SavedQuery $saved): void
    {
        $criteria = $saved->criteria;
        $this->completedFrom = $this->dateInput($criteria['completed_from'] ?? null);
        $this->completedTo = $this->dateInput($criteria['completed_to'] ?? null);
        $this->timeFrom = (string) ($criteria['time_from'] ?? '');
        $this->timeTo = (string) ($criteria['time_to'] ?? '');
        $this->customerId = $this->inputValue($criteria['customer_id'] ?? null);
        $this->agentId = $this->inputValue($criteria['agent_id'] ?? null);
        $this->institutionId = $this->inputValue($criteria['institution_id'] ?? null);
        $this->projectName = (string) ($criteria['project_name'] ?? '');
        $this->translatorName = (string) ($criteria['translator_name'] ?? '');
        $this->amountMin = $this->inputValue($criteria['amount_min'] ?? null);
        $this->amountMax = $this->inputValue($criteria['amount_max'] ?? null);
        $this->passport = '';
        $this->sortField = (string) $saved->sort_field;
        $this->sortDirection = (string) $saved->sort_direction;
        $this->page = 1;
    }

    public function deleteQuery(int $id, SavedQueryManager $manager): void
    {
        $manager->delete($this->user(), $id);
        session()->flash('status', '常用查询已删除。');
    }

    public function queueExport(ReportExportManager $manager): void
    {
        $manager->queueSearch($this->user(), $this->criteria());
        session()->flash('status', 'Excel 导出任务已进入队列。');
    }

    public function retryExport(string $id, ReportExportManager $manager): void
    {
        $manager->retry($this->user(), $id);
        session()->flash('status', '导出任务已重新进入队列。');
    }

    public function render(
        ReportSearch $search,
        SavedQueryManager $savedQueries,
        ReportExportManager $exports,
    ): View {
        $result = $search->paginate($this->criteria(), $this->perPage, $this->page);
        $saved = $savedQueries->visibleTo($this->user());
        $recentExports = $exports->recent($this->user());

        return view('livewire.reports.search', [
            'result' => $result,
            'savedQueries' => $saved,
            'recentExports' => $recentExports,
        ]);
    }

    /** @return array<string, int|string|null> */
    private function criteria(): array
    {
        return [
            'completed_from' => $this->completedFrom,
            'completed_to' => $this->completedTo,
            'time_from' => $this->timeFrom,
            'time_to' => $this->timeTo,
            'customer_id' => $this->customerId,
            'agent_id' => $this->agentId,
            'project_name' => $this->projectName,
            'institution_id' => $this->institutionId,
            'translator_name' => $this->translatorName,
            'amount_min' => $this->amountMin,
            'amount_max' => $this->amountMax,
            'passport' => $this->passport,
            'sort_field' => $this->sortField,
            'sort_direction' => $this->sortDirection,
        ];
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function inputValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function dateInput(mixed $value): string
    {
        return $value === null || $value === '' ? '' : substr((string) $value, 0, 10);
    }
}
