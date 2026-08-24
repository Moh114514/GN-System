<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerFollowupManager;
use App\Modules\Customer\Application\Services\CustomerStatusApprovalManager;
use App\Modules\Customer\Application\Services\CustomerStatusManager;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerDetail extends Component
{
    public int $customerId;

    public string $timelineType = '';

    public string $targetStatusId = '';

    public string $statusReason = '';

    public string $followupType = '';

    public string $followedUpOn = '';

    public string $followupContent = '';

    public string $transferTargetOwnerId = '';

    public string $transferReason = '';

    public string $transferReviewReason = '';

    public string $rollbackReviewReason = '';

    /** @var array<string, mixed> */
    public array $options = [];

    public function mount(int $customer, CustomerDirectory $directory, BusinessClock $clock): void
    {
        $directory->profile($customer);
        $this->customerId = $customer;
        $this->options = $directory->options();
        $this->followedUpOn = $clock->now()->toDateString();
        $this->followupType = __('customers.detail.followup.default_type');
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
        Flux::toast(variant: 'success', text: __('customers.toasts.status_updated'));
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
        Flux::toast(variant: 'success', text: __('customers.toasts.followup_saved'));
    }

    public function requestTransfer(CustomerTransferManager $manager): void
    {
        $this->validate([
            'transferTargetOwnerId' => ['required', 'integer'],
            'transferReason' => ['required', 'string', 'max:1000'],
        ]);
        $manager->request(
            customerId: $this->customerId,
            toOwnerId: (int) $this->transferTargetOwnerId,
            reason: $this->transferReason,
            actor: Auth::user(),
            ipAddress: request()->ip(),
        );
        $this->reset('transferTargetOwnerId', 'transferReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_requested'));
    }

    public function withdrawTransfer(CustomerTransferManager $manager): void
    {
        $pending = $manager->pendingForCustomer($this->customerId);
        if ($pending !== null) {
            $manager->withdraw((int) $pending['id'], Auth::user(), request()->ip());
        }
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_withdrawn'));
    }

    public function approveTransfer(CustomerTransferManager $manager): void
    {
        $this->validate(['transferReviewReason' => ['required', 'string', 'max:1000']]);
        $pending = $manager->pendingForCustomer($this->customerId);
        abort_if($pending === null, 422, __('customers.transfer.errors.not_pending'));
        $manager->approve((int) $pending['id'], $this->transferReviewReason, Auth::user(), request()->ip());
        $this->reset('transferReviewReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_approved'));
    }

    public function rejectTransfer(CustomerTransferManager $manager): void
    {
        $this->validate(['transferReviewReason' => ['required', 'string', 'max:1000']]);
        $pending = $manager->pendingForCustomer($this->customerId);
        abort_if($pending === null, 422, __('customers.transfer.errors.not_pending'));
        $manager->reject((int) $pending['id'], $this->transferReviewReason, Auth::user(), request()->ip());
        $this->reset('transferReviewReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_rejected'));
    }

    public function directTransfer(CustomerTransferManager $manager): void
    {
        $this->validate([
            'transferTargetOwnerId' => ['required', 'integer'],
            'transferReason' => ['required', 'string', 'max:1000'],
        ]);
        $manager->direct(
            customerId: $this->customerId,
            toOwnerId: (int) $this->transferTargetOwnerId,
            reason: $this->transferReason,
            actor: Auth::user(),
            ipAddress: request()->ip(),
        );
        $this->reset('transferTargetOwnerId', 'transferReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_completed'));
    }

    public function requestRollback(CustomerStatusApprovalManager $manager): void
    {
        $this->validate([
            'targetStatusId' => ['required', 'integer'],
            'statusReason' => ['required', 'string', 'max:1000'],
        ]);
        $manager->requestRollback(
            customerId: $this->customerId,
            targetStatusId: (int) $this->targetStatusId,
            reason: $this->statusReason,
            actor: Auth::user(),
            ipAddress: request()->ip(),
        );
        $this->reset('targetStatusId', 'statusReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.rollback_requested'));
    }

    public function withdrawRollback(CustomerStatusApprovalManager $manager): void
    {
        $pending = $manager->pendingForCustomer($this->customerId);
        if ($pending !== null) {
            $manager->withdraw((int) $pending['id'], Auth::user());
        }
        Flux::toast(variant: 'success', text: __('customers.toasts.rollback_withdrawn'));
    }

    public function approveRollback(CustomerStatusApprovalManager $manager): void
    {
        $this->validate(['rollbackReviewReason' => ['required', 'string', 'max:1000']]);
        $pending = $manager->pendingForCustomer($this->customerId);
        abort_if($pending === null, 422, __('customers.status_approval.errors.not_pending'));
        $manager->approve((int) $pending['id'], $this->rollbackReviewReason, Auth::user(), request()->ip());
        $this->reset('rollbackReviewReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.rollback_approved'));
    }

    public function rejectRollback(CustomerStatusApprovalManager $manager): void
    {
        $this->validate(['rollbackReviewReason' => ['required', 'string', 'max:1000']]);
        $pending = $manager->pendingForCustomer($this->customerId);
        abort_if($pending === null, 422, __('customers.status_approval.errors.not_pending'));
        $manager->reject((int) $pending['id'], $this->rollbackReviewReason, Auth::user());
        $this->reset('rollbackReviewReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.rollback_rejected'));
    }

    public function render(CustomerDirectory $directory, CustomerTransferManager $transfers, CustomerStatusApprovalManager $approvals): View
    {
        $customer = $directory->profile($this->customerId);

        return view('livewire.customers.customer-detail', [
            'customer' => $customer,
            'statusFlow' => $directory->statusFlow($this->customerId),
            'timeline' => $directory->timeline($this->customerId, $this->timelineType),
            'ownerCandidates' => $transfers->ownerCandidates(),
            'transferRequest' => $transfers->pendingForCustomer($this->customerId),
            'rollbackRequest' => $approvals->pendingForCustomer($this->customerId),
        ])->title(__('customers.title.detail'));
    }
}
