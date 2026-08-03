<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Modules\Settlement\Application\Services\SettlementNotificationDispatcher;
use App\Modules\Settlement\Application\Services\SettlementPeriodCalculator;
use App\Modules\Settlement\Application\Services\SettlementRunManager;
use App\Modules\Settlement\Infrastructure\Models\SettlementRun;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('月结中心')]
class SettlementCenter extends Component
{
    public string $boundaryDay = '1';

    public string $triggerTime = '09:00';

    public bool $confirmConfigurationChange = false;

    public string $historicalPeriodEnd = '';

    public function mount(SettlementPeriodCalculator $periods): void
    {
        $configuration = $periods->activeConfiguration(CarbonImmutable::now());
        $this->boundaryDay = (string) $configuration->boundary_day;
        $this->triggerTime = substr((string) $configuration->trigger_time, 0, 5);
    }

    public function generate(SettlementRunManager $manager): void
    {
        $run = $manager->start('manual', (int) Auth::id());
        session()->flash('status', "月结批次 {$run->id} 已进入处理队列。");
    }

    public function generateHistorical(SettlementRunManager $manager): void
    {
        $this->validate([
            'historicalPeriodEnd' => ['required', 'date_format:Y-m-d'],
        ]);
        try {
            $run = $manager->startHistorical($this->historicalPeriodEnd, (int) Auth::id());
            session()->flash('status', "往期月结批次 {$run->id} 已进入处理队列。");
        } catch (DomainException $exception) {
            $this->addError('historicalPeriodEnd', $exception->getMessage());
        }
    }

    public function retry(string $runId, SettlementRunManager $manager): void
    {
        $manager->retryFailed($runId);
        session()->flash('status', '失败的代理商月结已重新进入队列。');
    }

    public function retryNotification(string $runId, SettlementNotificationDispatcher $dispatcher): void
    {
        try {
            $dispatcher->retry($runId);
            session()->flash('status', '月结完成通知已重新进入队列。');
        } catch (DomainException $exception) {
            $this->addError('configuration', $exception->getMessage());
        }
    }

    public function saveConfiguration(SettlementPeriodCalculator $periods): void
    {
        $this->validate([
            'boundaryDay' => ['required', 'integer', 'between:1,28'],
            'triggerTime' => ['required', 'date_format:H:i'],
        ]);
        $hasUnfinished = SettlementRun::query()->whereIn('status', ['queued', 'running', 'partial_failed'])->exists();
        if ($hasUnfinished && ! $this->confirmConfigurationChange) {
            $this->addError('configuration', '当前存在未完成月结，请确认当前周期仍按旧配置执行后再保存。');

            return;
        }
        try {
            $configuration = $periods->saveConfiguration(
                (int) $this->boundaryDay,
                $this->triggerTime,
                (int) Auth::id(),
                CarbonImmutable::now(),
            );
            session()->flash('status', '新月结周期配置将从 '.$configuration->effective_from->format('Y-m-d').' 起生效。');
            $this->confirmConfigurationChange = false;
        } catch (DomainException $exception) {
            $this->addError('configuration', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $periods = app(SettlementPeriodCalculator::class)->recentClosedPeriods(CarbonImmutable::now(), 13);

        return view('livewire.settlements.settlement-center', [
            'runs' => SettlementRun::query()->latest('period_end')->limit(24)->get(),
            'historicalPeriods' => array_slice($periods, 1),
        ]);
    }
}
