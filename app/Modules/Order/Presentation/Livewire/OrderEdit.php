<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OrderEdit extends Component
{
    public int $orderId;

    /** @var array<string, mixed> */
    public array $orderDetails = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    public array $options = [];

    public string $institutionId = '';

    public string $agentId = '';

    public string $projectName = '';

    public string $treatmentProjectId = '';

    public string $amountKrw = '';

    public string $translatorName = '';

    public string $translatorLanguageId = '';

    public string $notes = '';

    public string $occurredOn = '';

    public string $quantity = '1';

    public string $unitPriceKrw = '';

    public string $specification = '';

    public string $itemNotes = '';

    public string $reason = '';

    public string $expectedUpdatedAt = '';

    public function mount(int $order, OrderManagementWorkspace $workspace): void
    {
        $this->orderId = $order;
        $this->orderDetails = $workspace->detail($order);
        abort_unless(($this->orderDetails['can_edit'] ?? false) === true, 404);
        $this->options = $workspace->options();
        $this->institutionId = (string) ($this->orderDetails['institution']['id'] ?? '');
        $this->agentId = (string) ($this->orderDetails['agent']['id'] ?? '');
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
        $this->occurredOn = (string) ($this->orderDetails['occurred_on'] ?? '');
        $item = $this->orderDetails['items'][0] ?? [];
        $this->quantity = (string) ($item['quantity'] ?? '1');
        $this->unitPriceKrw = (string) ($item['unit_price_krw'] ?? $this->orderDetails['amount_krw']);
        $this->specification = (string) ($item['specification'] ?? '');
        $this->itemNotes = (string) ($item['notes'] ?? '');
        $this->expectedUpdatedAt = (string) ($this->orderDetails['updated_at'] ?? '');
    }

    public function save(OrderManagementWorkspace $workspace): void
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'agentId' => ['required', 'integer'],
            'projectName' => ['required', 'string', 'max:255'],
            'amountKrw' => ['required', 'integer', 'min:0'],
            'occurredOn' => [$this->orderDetails['status'] === 'completed' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unitPriceKrw' => ['required', 'integer', 'min:0'],
            'specification' => ['nullable', 'string', 'max:1000'],
            'itemNotes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
            'translatorName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $workspace->updatePending(new OrderUpdateData(
                orderId: $this->orderId,
                institutionId: (int) $this->institutionId,
                agentId: (int) $this->agentId,
                projectName: $this->projectName,
                amountKrw: (int) $this->amountKrw,
                translatorName: $this->translatorName === '' ? null : $this->translatorName,
                notes: $this->notes === '' ? null : $this->notes,
                treatmentProjectId: $this->treatmentProjectId === '' ? null : (int) $this->treatmentProjectId,
                translatorLanguageId: $this->translatorLanguageId === '' ? null : (int) $this->translatorLanguageId,
                translatorLanguageName: null,
                occurredOn: $this->occurredOn === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $this->occurredOn),
                items: [[
                    'project_name' => $this->projectName,
                    'specification' => $this->specification === '' ? null : $this->specification,
                    'quantity' => $this->quantity,
                    'unit_price_krw' => (int) $this->unitPriceKrw,
                    'amount_krw' => (int) $this->amountKrw,
                    'notes' => $this->itemNotes === '' ? null : $this->itemNotes,
                ]],
                reason: $this->reason,
                expectedUpdatedAt: $this->expectedUpdatedAt,
            ), (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: __('orders.errors.unexpected', ['message' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: __('orders.messages.updated'));
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
        return view('livewire.orders.order-edit', ['order' => $this->orderDetails])->title(__('orders.edit_title'));
    }
}
