<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Services\DailyOrderWorkspace;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('订单管理')]
class OrderCenter extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $channelFilter = '';

    public string $institutionFilter = '';

    public string $agentFilter = '';

    public int $perPage = 20;

    public bool $showCreate = false;

    public bool $showDeleted = false;

    public string $customerSearch = '';

    public string $customerId = '';

    /** @var array<string, mixed>|null */
    public ?array $selectedCustomer = null;

    /** @var array<int, array<string, mixed>> */
    public array $customerCandidates = [];

    public string $institutionId = '';

    public string $channel = 'agent';

    public string $agentId = '';

    public string $directSalesSourceId = '';

    public string $projectName = '';

    public string $treatmentProjectId = '';

    public string $amountKrw = '';

    public string $orderStatus = 'pending';

    public string $completedOn = '';

    public string $translatorName = '';

    public string $translatorLanguageId = '';

    public string $notes = '';

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    /** @var array<string, array<string, mixed>> */
    protected array $queryString = [
        'search' => ['except' => ''],
        'statusFilter' => ['except' => ''],
        'channelFilter' => ['except' => ''],
        'institutionFilter' => ['except' => ''],
        'agentFilter' => ['except' => ''],
        'perPage' => ['except' => 20],
        'showDeleted' => ['except' => false],
    ];

    public function mount(OrderManagementWorkspace $workspace): void
    {
        $this->options = $workspace->options();
        $this->completedOn = now('Asia/Shanghai')->format('Y-m-d\TH:i');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'channelFilter', 'institutionFilter', 'agentFilter', 'perPage', 'showDeleted'], true)) {
            $this->resetPage();
        }
        if ($property === 'customerSearch') {
            $this->customerCandidates = app(OrderManagementWorkspace::class)->customerCandidates($this->customerSearch);
        }
    }

    public function openCreate(OrderManagementWorkspace $workspace): void
    {
        $this->showCreate = true;
        $this->customerCandidates = $workspace->customerCandidates($this->customerSearch);
    }

    public function closeCreate(): void
    {
        $this->showCreate = false;
        $this->resetOrderForm();
        $this->resetValidation();
    }

    public function selectCustomer(int $customerId, OrderManagementWorkspace $workspace): void
    {
        $customer = $workspace->customer($customerId);
        $this->selectedCustomer = $customer;
        $this->customerId = (string) $customerId;
        $this->customerSearch = '';
        $this->customerCandidates = [];
        $this->channel = (string) $customer['original_channel'];
        $this->agentId = (string) ($customer['source_agent_id'] ?? '');
        $this->directSalesSourceId = (string) ($customer['source_direct_sales_id'] ?? '');
        $this->resetValidation('customerId');
    }

    public function clearCustomer(): void
    {
        $this->customerId = '';
        $this->selectedCustomer = null;
        $this->customerCandidates = app(OrderManagementWorkspace::class)->customerCandidates('');
    }

    public function save(DailyOrderWorkspace $workspace): void
    {
        $this->validate([
            'customerId' => ['required', 'integer'],
            'institutionId' => ['required', 'integer'],
            'channel' => ['required', 'in:agent,direct'],
            'agentId' => [$this->channel === 'agent' ? 'required' : 'nullable', 'integer'],
            'directSalesSourceId' => [$this->channel === 'direct' ? 'required' : 'nullable', 'integer'],
            'projectName' => ['required_without:treatmentProjectId', 'nullable', 'string', 'max:255'],
            'treatmentProjectId' => ['nullable', 'integer'],
            'amountKrw' => ['required', 'integer', 'min:0'],
            'orderStatus' => ['required', 'in:pending,completed'],
            'completedOn' => [$this->orderStatus === 'completed' ? 'required' : 'nullable', 'date'],
            'translatorName' => ['nullable', 'string', 'max:255'],
            'translatorLanguageId' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $workspace->create(new DailyOrderData(
                customerId: (int) $this->customerId,
                institutionId: (int) $this->institutionId,
                channel: $this->channel,
                agentId: $this->channel === 'agent' ? (int) $this->agentId : null,
                directSalesSourceId: $this->channel === 'direct' ? (int) $this->directSalesSourceId : null,
                projectName: $this->projectName,
                amountKrw: (int) $this->amountKrw,
                status: $this->orderStatus,
                completedOn: $this->orderStatus === 'completed'
                    ? CarbonImmutable::parse($this->completedOn, 'Asia/Shanghai')
                    : null,
                translatorName: $this->translatorName === '' ? null : $this->translatorName,
                notes: $this->notes === '' ? null : $this->notes,
                ownerId: (int) Auth::id(),
                ipAddress: request()->ip(),
                treatmentProjectId: $this->treatmentProjectId === '' ? null : (int) $this->treatmentProjectId,
                translatorLanguageId: $this->translatorLanguageId === '' ? null : (int) $this->translatorLanguageId,
            ));
        } catch (DomainException $exception) {
            $this->addError('order', $exception->getMessage());

            return;
        }

        $this->showCreate = false;
        $this->resetOrderForm();
        $this->resetPage();
        session()->flash('status', '订单已保存。');
    }

    public function complete(int $orderId, DailyOrderWorkspace $workspace): void
    {
        try {
            $workspace->complete($orderId, CarbonImmutable::now('Asia/Shanghai'), (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            $this->addError('completion', $exception->getMessage());

            return;
        }
        session()->flash('status', '订单已完成，推广费与术后提醒已同步固化。');
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'channelFilter', 'institutionFilter', 'agentFilter', 'showDeleted');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(OrderManagementWorkspace $workspace): View
    {
        $orders = $workspace->paginate([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'channel' => $this->channelFilter,
            'institution_id' => $this->institutionFilter === '' ? null : (int) $this->institutionFilter,
            'agent_id' => $this->agentFilter === '' ? null : (int) $this->agentFilter,
        ], in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20, $this->showDeleted);

        return view('livewire.orders.order-center', compact('orders'));
    }

    private function resetOrderForm(): void
    {
        $this->reset(
            'customerSearch',
            'customerId',
            'selectedCustomer',
            'customerCandidates',
            'institutionId',
            'agentId',
            'directSalesSourceId',
            'projectName',
            'treatmentProjectId',
            'amountKrw',
            'translatorName',
            'translatorLanguageId',
            'notes',
        );
        $this->channel = 'agent';
        $this->orderStatus = 'pending';
        $this->completedOn = now('Asia/Shanghai')->format('Y-m-d\TH:i');
    }
}
