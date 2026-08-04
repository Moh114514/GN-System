<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerFollowupManager;
use App\Modules\Customer\Application\Services\CustomerStatusManager;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('客户详情')]
class CustomerDetail extends Component
{
    public int $customerId;

    public string $timelineType = '';

    public string $targetStatusId = '';

    public string $statusReason = '';

    public string $followupType = '日常回访';

    public string $followedUpOn = '';

    public string $followupContent = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function mount(int $customer, CustomerDirectory $directory): void
    {
        $directory->profile($customer);
        $this->customerId = $customer;
        $this->options = $directory->options();
        $this->followedUpOn = now()->toDateString();
    }

    public function changeStatus(CustomerStatusManager $manager): void
    {
        $this->validate([
            'targetStatusId' => ['required', 'integer'],
            'statusReason' => ['required', 'string', 'max:1000'],
        ]);
        $manager->change(
            customerId: $this->customerId,
            targetStatusId: (int) $this->targetStatusId,
            reason: $this->statusReason,
            actor: Auth::user(),
            ipAddress: request()->ip(),
        );
        $this->reset('targetStatusId', 'statusReason');
        Flux::toast(variant: 'success', text: '客户状态已更新。');
    }

    public function recordFollowup(CustomerFollowupManager $manager): void
    {
        $this->validate([
            'followupType' => ['required', 'string', 'max:32'],
            'followedUpOn' => ['required', 'date'],
            'followupContent' => ['required', 'string', 'max:5000'],
        ]);
        $manager->record(
            customerId: $this->customerId,
            type: $this->followupType,
            followedUpOn: CarbonImmutable::parse($this->followedUpOn),
            content: $this->followupContent,
            actorId: (int) Auth::id(),
            ipAddress: request()->ip(),
        );
        $this->followupContent = '';
        Flux::toast(variant: 'success', text: '跟进记录已保存。');
    }

    public function render(CustomerDirectory $directory): View
    {
        return view('livewire.customers.customer-detail', [
            'customer' => $directory->profile($this->customerId),
            'timeline' => $directory->timeline($this->customerId, $this->timelineType),
        ]);
    }
}
