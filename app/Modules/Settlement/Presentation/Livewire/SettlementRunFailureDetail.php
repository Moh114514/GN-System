<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\SettlementRunFailureReader;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('月结失败详情')]
class SettlementRunFailureDetail extends Component
{
    public string $runId;

    public function mount(string $run): void
    {
        $this->runId = $run;
    }

    public function retryAll(SettlementRunManager $manager): void
    {
        $manager->retryFailed($this->runId);
        Flux::toast(variant: 'success', text: '失败项已重新提交处理队列。');
    }

    public function render(SettlementRunFailureReader $reader): View
    {
        $run = SettlementRun::query()->findOrFail($this->runId);

        return view('livewire.settlements.settlement-run-failure-detail', [
            'run' => $run,
            'failures' => $reader->read($run),
        ]);
    }
}
