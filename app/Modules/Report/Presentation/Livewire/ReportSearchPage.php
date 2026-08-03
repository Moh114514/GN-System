<?php

namespace App\Modules\Report\Presentation\Livewire;

use App\Models\User;
use App\Modules\Report\Application\Services\ReportExportManager;
use App\Modules\Report\Application\Services\ReportSearch;
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
        if ($property !== 'page') {
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

    public function downloadExport(ReportExportManager $manager): void
    {
        $export = $manager->startSearch($this->user(), $this->criteria());

        if ($export->status === 'queued') {
            session()->flash('status', '结果较多，导出任务已进入队列；完成后可在最近导出中下载。');

            return;
        }

        if ($export->status !== 'completed') {
            session()->flash('error', $export->failure_reason ?? '导出失败，请缩小筛选范围后重试。');

            return;
        }

        $this->redirectRoute('reports.exports.download', ['export' => $export]);
    }

    public function retryExport(string $id, ReportExportManager $manager): void
    {
        $manager->retry($this->user(), $id);
        session()->flash('status', '导出任务已重新进入队列。');
    }

    public function render(
        ReportSearch $search,
        ReportExportManager $exports,
    ): View {
        $result = $search->paginate($this->criteria(), $this->perPage, $this->page);
        $recentExports = $exports->recent($this->user());

        return view('livewire.reports.search', [
            'result' => $result,
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
}
