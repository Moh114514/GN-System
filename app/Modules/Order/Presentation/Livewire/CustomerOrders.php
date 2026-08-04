<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\DailyOrderData;
use App\Modules\Order\Application\Services\DailyOrderWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('客户订单')]
class CustomerOrders extends Component
{
    public int $customerId;

    public string $institutionId = '';

    public string $channel = 'agent';

    public string $agentId = '';

    public string $directSalesSourceId = '';

    public string $projectName = '';

    public string $treatmentProjectId = '';

    public string $amountKrw = '';

    public string $status = 'pending';

    public string $completedOn = '';

    public string $translatorName = '';

    public string $translatorLanguageId = '';

    public string $notes = '';

    /** @var array<string, mixed> */
    public array $context = [];

    public function mount(int $customer, DailyOrderWorkspace $workspace): void
    {
        $this->customerId = $customer;
        $this->loadContext($workspace);
        $profile = $this->context['customer'];
        $this->channel = (string) $profile['original_channel'];
        $this->agentId = (string) ($profile['source_agent_id'] ?? '');
        $this->directSalesSourceId = (string) ($profile['source_direct_sales_id'] ?? '');
        $this->completedOn = now('Asia/Shanghai')->format('Y-m-d\TH:i');
    }

    public function save(DailyOrderWorkspace $workspace): void
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'channel' => ['required', 'in:agent,direct'],
            'agentId' => [$this->channel === 'agent' ? 'required' : 'nullable', 'integer'],
            'directSalesSourceId' => [$this->channel === 'direct' ? 'required' : 'nullable', 'integer'],
            'projectName' => ['required_without:treatmentProjectId', 'nullable', 'string', 'max:255'],
            'treatmentProjectId' => ['nullable', 'integer'],
            'amountKrw' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'in:pending,completed'],
            'completedOn' => [$this->status === 'completed' ? 'required' : 'nullable', 'date'],
            'translatorName' => ['nullable', 'string', 'max:255'],
            'translatorLanguageId' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        try {
            $workspace->create(new DailyOrderData(
                customerId: $this->customerId,
                institutionId: (int) $this->institutionId,
                channel: $this->channel,
                agentId: $this->channel === 'agent' ? (int) $this->agentId : null,
                directSalesSourceId: $this->channel === 'direct' ? (int) $this->directSalesSourceId : null,
                projectName: $this->projectName,
                amountKrw: (int) $this->amountKrw,
                status: $this->status,
                completedOn: $this->status === 'completed'
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
        $this->reset('institutionId', 'projectName', 'treatmentProjectId', 'amountKrw', 'translatorName', 'translatorLanguageId', 'notes');
        $this->status = 'pending';
        Flux::toast(variant: 'success', text: '订单已保存。');
        $this->loadContext($workspace);
    }

    public function complete(int $orderId, DailyOrderWorkspace $workspace): void
    {
        try {
            $workspace->complete($orderId, CarbonImmutable::now('Asia/Shanghai'), (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            $this->addError('completion', $exception->getMessage());

            return;
        }
        Flux::toast(variant: 'success', text: '订单已完成，推广费已按当前有效规则固化。');
        $this->loadContext($workspace);
    }

    public function render(): View
    {
        return view('livewire.orders.customer-orders');
    }

    private function loadContext(DailyOrderWorkspace $workspace): void
    {
        $this->context = $workspace->context($this->customerId);
    }
}
