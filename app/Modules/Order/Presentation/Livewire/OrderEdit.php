<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('编辑订单')]
class OrderEdit extends Component
{
    public int $orderId;

    /** @var array<string, mixed> */
    public array $orderDetails = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    public string $institutionId = '';

    public string $channel = 'agent';

    public string $agentId = '';

    public string $directSalesSourceId = '';

    public string $projectName = '';

    public string $treatmentProjectId = '';

    public string $amountKrw = '';

    public string $translatorName = '';

    public string $translatorLanguageId = '';

    public string $notes = '';

    public function mount(int $order, OrderManagementWorkspace $workspace): void
    {
        $this->orderId = $order;
        $this->orderDetails = $workspace->detail($order);
        abort_unless($this->orderDetails['status'] === 'pending' && $this->orderDetails['deleted_at'] === null, 404);
        $this->options = $workspace->options();
        $this->institutionId = (string) ($this->orderDetails['institution']['id'] ?? '');
        $this->channel = (string) $this->orderDetails['channel'];
        $this->agentId = (string) ($this->orderDetails['agent']['id'] ?? '');
        $this->directSalesSourceId = (string) ($this->orderDetails['direct_source']['id'] ?? '');
        $this->projectName = (string) $this->orderDetails['project_name'];
        $this->treatmentProjectId = collect($this->options['treatment_projects'] ?? [])->contains(fn (array $item): bool => (int) $item['id'] === (int) ($this->orderDetails['treatment_project_id'] ?? 0))
            ? (string) $this->orderDetails['treatment_project_id']
            : '';
        $this->amountKrw = (string) $this->orderDetails['amount_krw'];
        $this->translatorName = (string) ($this->orderDetails['translator_name'] ?? '');
        $this->translatorLanguageId = collect($this->options['translator_languages'] ?? [])->contains(fn (array $item): bool => (int) $item['id'] === (int) ($this->orderDetails['translator_language_id'] ?? 0))
            ? (string) $this->orderDetails['translator_language_id']
            : '';
        $this->notes = (string) ($this->orderDetails['notes'] ?? '');
    }

    public function save(OrderManagementWorkspace $workspace): void
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'channel' => ['required', 'in:agent,direct'],
            'agentId' => [$this->channel === 'agent' ? 'required' : 'nullable', 'integer'],
            'directSalesSourceId' => [$this->channel === 'direct' ? 'required' : 'nullable', 'integer'],
            'projectName' => ['required', 'string', 'max:255'],
            'amountKrw' => ['required', 'integer', 'min:0'],
            'translatorName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $workspace->updatePending(new OrderUpdateData(
                orderId: $this->orderId,
                institutionId: (int) $this->institutionId,
                channel: $this->channel,
                agentId: $this->channel === 'agent' ? (int) $this->agentId : null,
                directSalesSourceId: $this->channel === 'direct' ? (int) $this->directSalesSourceId : null,
                projectName: $this->projectName,
                amountKrw: (int) $this->amountKrw,
                translatorName: $this->translatorName === '' ? null : $this->translatorName,
                notes: $this->notes === '' ? null : $this->notes,
                treatmentProjectId: $this->treatmentProjectId === '' ? null : (int) $this->treatmentProjectId,
                translatorLanguageId: $this->translatorLanguageId === '' ? null : (int) $this->translatorLanguageId,
                translatorLanguageName: null,
            ), (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: '订单已更新。');
        $this->redirectRoute('orders.show', ['order' => $this->orderId], navigate: true);
    }

    public function updatedTreatmentProjectId(): void
    {
        if ($this->treatmentProjectId === '') {
            return;
        }

        $project = collect($this->options['treatment_projects'] ?? [])
            ->first(fn (array $item): bool => (int) $item['id'] === (int) $this->treatmentProjectId);

        if ($project !== null) {
            $this->projectName = (string) $project['name'];
        }
    }

    public function render(): View
    {
        return view('livewire.orders.order-edit', ['order' => $this->orderDetails]);
    }
}
