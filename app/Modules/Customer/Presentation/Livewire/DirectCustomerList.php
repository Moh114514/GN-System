<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Support\DateRange;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class DirectCustomerList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusId = '';

    public string $ownerId = '';

    public string $createdFrom = '';

    public string $createdTo = '';

    public int $perPage = 20;

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusId' => ['except' => ''],
        'ownerId' => ['except' => ''],
        'createdFrom' => ['except' => ''],
        'createdTo' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(CustomerDirectory $directory): void
    {
        abort_unless(Auth::user()?->isSuperAdmin() || Auth::user()?->isDirectCustomerManager(), 403);
        $this->options = $directory->options();
        $this->options['users'] = $directory->directOwnerCandidates();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusId', 'ownerId', 'createdFrom', 'createdTo', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusId', 'ownerId', 'createdFrom', 'createdTo');
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
            'createdFrom.date_format' => __('customers.direct.list.validation.created_from_format'),
            'createdTo.date_format' => __('customers.direct.list.validation.created_to_format'),
            'createdTo.after_or_equal' => __('customers.direct.list.validation.created_range'),
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
                'source_type' => 'direct',
                'search' => $this->search,
                'status_id' => $this->statusId === '' ? null : (int) $this->statusId,
                'owner_id' => $this->ownerId === '' ? null : (int) $this->ownerId,
                'created_from' => $this->createdFrom,
                'created_to' => $this->createdTo,
            ], $perPage);

        return view('livewire.customers.direct-customer-list', [
            'customers' => $customers,
            'ownerCandidates' => $this->options['users'] ?? [],
        ])->title(__('customers.direct.title.list'));
    }
}
