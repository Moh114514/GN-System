<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Models\User;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Domain\CustomerSourceType;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class CustomerSourceList extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sourceType = '';

    public string $createdTo = '';

    public int $perPage = 20;

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'sourceType' => ['except' => ''],
        'createdTo' => ['except' => ''],
        'perPage' => ['except' => 20],
    ];

    public function mount(): void
    {
        $this->authorizeOrganizationWideRead();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sourceType', 'createdTo', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function render(CustomerDirectory $directory): View
    {
        $this->authorizeOrganizationWideRead();
        $sourceType = $this->sourceType === ''
            ? null
            : CustomerSourceType::tryFrom($this->sourceType)?->value;
        abort_if($this->sourceType !== '' && $sourceType === null, 422);

        $perPage = in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20;
        $hasDateError = false;
        try {
            $this->validate(['createdTo' => ['nullable', 'date_format:Y-m-d']]);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $hasDateError = true;
        }
        $customers = $hasDateError
            ? new LengthAwarePaginator([], 0, $perPage, 1, ['path' => request()->url(), 'query' => request()->query()])
            : $directory->paginate([
                'source_type' => $sourceType,
                'search' => $this->search,
                'created_to' => $this->createdTo,
            ], $perPage);

        return view('livewire.customers.customer-source-list', [
            'customers' => $customers,
            'sourceTypes' => array_map(fn (CustomerSourceType $type): array => [
                'value' => $type->value,
                'label' => __('customers.source_list.types.'.$type->value),
            ], CustomerSourceType::cases()),
            'selectedSourceLabel' => $sourceType === null
                ? __('customers.source_list.all_sources')
                : __('customers.source_list.types.'.$sourceType),
            'createdTo' => $this->createdTo,
            'perPage' => $perPage,
        ])->title(__('customers.source_list.title'));
    }

    private function authorizeOrganizationWideRead(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && ($user->isSuperAdmin() || $user->isOperationsDirector()), 403);
    }
}
