<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('订单回收站')]
class OrderRecycleBin extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public string $channelFilter = '';

    public string $institutionFilter = '';

    public string $agentFilter = '';

    public int $perPage = 20;

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
    ];

    public function mount(OrderManagementWorkspace $workspace): void
    {
        $this->assertCanView();
        $this->options = $workspace->options();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'channelFilter', 'institutionFilter', 'agentFilter', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'statusFilter', 'channelFilter', 'institutionFilter', 'agentFilter');
        $this->perPage = 20;
        $this->resetPage();
    }

    public function render(OrderManagementWorkspace $workspace): View
    {
        $this->assertCanView();
        $orders = $workspace->paginate([
            'search' => $this->search,
            'status' => $this->statusFilter,
            'channel' => $this->channelFilter,
            'institution_id' => $this->institutionFilter === '' ? null : (int) $this->institutionFilter,
            'agent_id' => $this->agentFilter === '' ? null : (int) $this->agentFilter,
        ], in_array($this->perPage, [20, 50, 100], true) ? $this->perPage : 20, true, true);

        return view('livewire.orders.order-recycle-bin', compact('orders'));
    }

    private function assertCanView(): void
    {
        abort_unless((bool) Auth::user()?->is_super_admin, 403);
    }
}
