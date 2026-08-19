<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
class TimeTravel extends Component
{
    public string $simulationDate = '';

    public string $simulationTime = '';

    public bool $runSettlements = true;

    public bool $runReminderMaterialization = true;

    public bool $runReminderDispatch = true;

    public string $lastExecution = '';

    public function mount(BusinessClock $clock): void
    {
        abort_unless($clock->isAvailable(), 404);
        $this->fillFrom($clock->now());
    }

    public function enable(BusinessClock $clock): void
    {
        $at = $this->validatedTime();
        if ($at === null) {
            return;
        }
        $clock->set($at);
        $this->fillFrom($at);
        Flux::toast(variant: 'success', text: __('config.time_travel.toast.enabled'));
    }

    public function adjust(string $unit, BusinessClock $clock): void
    {
        $at = $clock->shift($unit);
        $this->fillFrom($at);
        Flux::toast(variant: 'success', text: __('config.time_travel.toast.enabled'));
    }

    public function restore(BusinessClock $clock): void
    {
        $clock->disable();
        $this->fillFrom($clock->realNow());
        $this->lastExecution = '';
        Flux::toast(variant: 'success', text: __('config.time_travel.toast.disabled'));
    }

    public function setAndExecute(BusinessClock $clock): void
    {
        $this->validate([
            'runSettlements' => ['boolean'],
            'runReminderMaterialization' => ['boolean'],
            'runReminderDispatch' => ['boolean'],
        ]);
        if (! $this->runSettlements && ! $this->runReminderMaterialization && ! $this->runReminderDispatch) {
            $this->addError('actions', __('config.time_travel.errors.no_actions'));

            return;
        }

        $at = $this->validatedTime();
        if ($at === null) {
            return;
        }
        $clock->set($at);
        $commands = [];
        if ($this->runSettlements) {
            $commands[] = 'settlements';
        }
        if ($this->runReminderMaterialization) {
            $commands[] = 'reminders';
        }
        if ($this->runReminderDispatch) {
            $commands[] = 'notifications';
        }

        $completed = [];
        try {
            foreach ($commands as $command) {
                $name = match ($command) {
                    'settlements' => 'app:generate-settlements',
                    'reminders' => 'app:materialize-reminders',
                    'notifications' => 'app:dispatch-reminder-notifications',
                };
                $exitCode = Artisan::call($name);
                if ($exitCode !== 0) {
                    throw new RuntimeException("The {$name} command failed with exit code {$exitCode}.");
                }
                $completed[] = $command;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->lastExecution = implode(', ', $completed);
            Flux::toast(variant: 'danger', text: __('config.time_travel.toast.execution_failed'));

            return;
        }

        $this->fillFrom($at);
        $this->lastExecution = implode(', ', $commands);
        Flux::toast(variant: 'success', text: __('config.time_travel.submission_notice'));
    }

    public function render(BusinessClock $clock): View
    {
        return view('livewire.configuration.time-travel', [
            'available' => $clock->isAvailable(),
            'active' => $clock->isActive(),
            'businessNow' => $clock->now(),
            'realNow' => $clock->realNow(),
        ])->title(__('config.time_travel.title'));
    }

    private function validatedTime(): ?CarbonImmutable
    {
        $this->validate([
            'simulationDate' => ['required', 'date_format:Y-m-d'],
            'simulationTime' => ['required', 'date_format:H:i'],
        ]);

        $value = $this->simulationDate.' '.$this->simulationTime;
        try {
            $at = CarbonImmutable::createFromFormat('!Y-m-d H:i', $value, (string) config('app.timezone'));
        } catch (Throwable) {
            $at = false;
        }
        if (! $at instanceof CarbonImmutable || $at->format('Y-m-d H:i') !== $value) {
            $this->addError('simulationTime', __('config.time_travel.errors.invalid_time'));

            return null;
        }

        return $at;
    }

    private function fillFrom(CarbonImmutable $at): void
    {
        $localized = $at->setTimezone((string) config('app.timezone'));
        $this->simulationDate = $localized->format('Y-m-d');
        $this->simulationTime = $localized->format('H:i');
    }
}
