<?php

namespace App\Modules\Settlement\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Settlement\Application\Contracts\DirectCommissionConfigurationGateway;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class DirectCustomerConfiguration extends Component
{
    public string $rateBps = '';

    public string $effectiveFrom = '';

    public string $effectiveUntil = '';

    public string $reason = '';

    /** @var array{current: array<string, mixed>|null, history: list<array<string, mixed>>} */
    public array $configuration = ['current' => null, 'history' => []];

    public function mount(DirectCommissionConfigurationGateway $gateway, BusinessClock $clock): void
    {
        $this->effectiveFrom = $clock->now()->toDateString();
        $this->reload($gateway, $clock);
    }

    public function saveRate(DirectCommissionConfigurationGateway $gateway, BusinessClock $clock): void
    {
        $this->validate([
            'rateBps' => ['required', 'integer', 'between:0,10000'],
            'effectiveFrom' => ['required', 'date'],
            'effectiveUntil' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $actorId = Auth::id();
            abort_unless(is_int($actorId), 403);
            $gateway->saveRate(
                rateBps: (int) $this->rateBps,
                effectiveFrom: CarbonImmutable::parse($this->effectiveFrom),
                effectiveUntil: $this->effectiveUntil === '' ? null : CarbonImmutable::parse($this->effectiveUntil),
                reason: $this->reason,
                actorId: $actorId,
                ipAddress: request()->ip(),
            );
            $this->reason = '';
            $this->reload($gateway, $clock);
            Flux::toast(variant: 'success', text: __('config.direct_customer.toast.rate_saved'));
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function render(): View
    {
        return view('livewire.configuration.direct-customer-configuration')
            ->title(__('config.direct_customer.title'));
    }

    private function reload(DirectCommissionConfigurationGateway $gateway, BusinessClock $clock): void
    {
        $this->configuration = $gateway->configuration($clock->now());
        $current = $this->configuration['current'];
        if ($current !== null && $this->rateBps === '') {
            $this->rateBps = (string) $current['rate_bps'];
        }
    }
}
