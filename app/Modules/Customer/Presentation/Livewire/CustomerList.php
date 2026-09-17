<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerTransferManager;
use App\Support\DateRange;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusId = '';

    public string $agentId = '';

    public string $institutionId = '';

    public string $ownerId = '';

    public string $ownerState = '';

    public string $transferStatus = '';

    public string $businessGroupId = '';

    public string $createdFrom = '';

    public string $createdTo = '';

    public int $perPage = 20;

    /** @var list<int> */
    public array $selectedCustomerIds = [];

    public string $bulkTransferTargetOwnerId = '';

    public string $bulkTransferReason = '';

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusId' => ['except' => ''],
        'agentId' => ['except' => ''],
        'institutionId' => ['except' => ''],
        'ownerId' => ['except' => ''],
        'ownerState' => ['except' => ''],
        'transferStatus' => ['except' => ''],
        'businessGroupId' => ['except' => ''],
        'createdFrom' => ['except' => ''],
        'createdTo' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(CustomerDirectory $directory): void
    {
        $this->options = $directory->options();
    }

    public function updated(string $property): void
    {
        if ($property === 'ownerId' && $this->ownerId !== '') {
            $this->ownerState = '';
        }
        if ($property === 'ownerState' && $this->ownerState !== '') {
            $this->ownerId = '';
        }
        if (in_array($property, ['search', 'statusId', 'agentId', 'institutionId', 'ownerId', 'ownerState', 'transferStatus', 'businessGroupId', 'createdFrom', 'createdTo', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusId', 'agentId', 'institutionId', 'ownerId', 'ownerState', 'transferStatus', 'businessGroupId', 'createdFrom', 'createdTo');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function applyDatePreset(string $preset, BusinessClock $clock): void
    {
        $range = DateRange::preset($preset, $clock->now());
        $this->createdFrom = $range->startAt?->toDateString() ?? '';
        $this->createdTo = $range->endExclusive?->subDay()->toDateString() ?? '';
        $this->resetPage();
    }

    public function bulkTransfer(CustomerTransferManager $manager): void
    {
        $this->validate([
            'selectedCustomerIds' => ['required', 'array', 'min:1'],
            'selectedCustomerIds.*' => ['integer', 'distinct'],
            'bulkTransferTargetOwnerId' => ['required', 'integer'],
            'bulkTransferReason' => ['required', 'string', 'max:1000'],
        ]);
        $manager->batch(
            customerIds: array_map('intval', $this->selectedCustomerIds),
            toOwnerId: (int) $this->bulkTransferTargetOwnerId,
            reason: $this->bulkTransferReason,
            actor: auth()->user(),
            ipAddress: request()->ip(),
        );
        $this->reset('selectedCustomerIds', 'bulkTransferTargetOwnerId', 'bulkTransferReason');
        Flux::toast(variant: 'success', text: __('customers.toasts.transfer_completed'));
    }

    /** @return array<string, array<int, string>> */
    protected function rules(): array
    {
        return [
            'createdFrom' => ['nullable', 'date_format:Y-m-d'],
            'createdTo' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:createdFrom'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'createdFrom.date_format' => __('customers.list.validation.created_from_format'),
            'createdTo.date_format' => __('customers.list.validation.created_to_format'),
            'createdTo.after_or_equal' => __('customers.list.validation.created_range'),
        ];
    }

    public function render(CustomerDirectory $directory): View
    {
        $hasDateError = false;
        try {
            $this->validate();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $hasDateError = true;
        }

        $perPage = in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20;
        $customers = $hasDateError
            ? new LengthAwarePaginator([], 0, $perPage, 1, ['path' => request()->url(), 'query' => request()->query()])
            : $directory->paginate([
                'search' => $this->search,
                'status_id' => $this->statusId === '' ? null : (int) $this->statusId,
                'agent_id' => $this->agentId === '' ? null : (int) $this->agentId,
                'institution_id' => $this->institutionId === '' ? null : (int) $this->institutionId,
                'owner_id' => $this->ownerId === '' ? null : (int) $this->ownerId,
                'owner_state' => $this->ownerState,
                'transfer_status' => $this->transferStatus,
                'business_group_id' => $this->businessGroupId === '' ? null : (int) $this->businessGroupId,
                'created_from' => $this->createdFrom,
                'created_to' => $this->createdTo,
            ], $perPage);

        return view('livewire.customers.customer-list', [
            'customers' => $customers,
            'ownerCandidates' => $directory->ownerCandidates(),
        ])
            ->title(__('customers.title.list'));
    }
}
