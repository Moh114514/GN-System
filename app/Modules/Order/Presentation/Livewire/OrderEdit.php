<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Data\OrderUpdateData;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    public string $translatorName = '';

    public string $translatorLanguageId = '';

    public string $notes = '';

    public string $occurredOn = '';

    /** @var array<int, array{project_name: string, treatment_project_id: string, specification: string, quantity: string, unit_price_krw: string, amount_krw: string, notes: string}> */
    public array $items = [];

    public string $reason = '';

    public string $expectedUpdatedAt = '';

    /** @var array<string, int|string|bool>|null */
    public ?array $commissionPreview = null;

    public string $commissionPreviewError = '';

    public function mount(int $order, OrderManagementWorkspace $workspace): void
    {
        $this->orderId = $order;
        $this->orderDetails = $workspace->detail($order);
        abort_unless(($this->orderDetails['can_edit'] ?? false) === true, 404);
        $this->options = $workspace->options();
        $this->institutionId = (string) ($this->orderDetails['institution']['id'] ?? '');
        $this->agentId = (string) ($this->orderDetails['agent']['id'] ?? '');
        $this->translatorName = (string) ($this->orderDetails['translator_name'] ?? '');
        $this->translatorLanguageId = collect($this->options['translator_languages'] ?? [])->contains(fn (array $item): bool => (int) $item['id'] === (int) ($this->orderDetails['translator_language_id'] ?? 0))
            ? (string) $this->orderDetails['translator_language_id']
            : '';
        $this->notes = (string) ($this->orderDetails['notes'] ?? '');
        $this->occurredOn = (string) ($this->orderDetails['occurred_on'] ?? '');
        $this->items = array_map(static fn (array $item): array => [
            'project_name' => (string) ($item['project_name'] ?? ''),
            'treatment_project_id' => (string) ($item['treatment_project_id'] ?? ''),
            'specification' => (string) ($item['specification'] ?? ''),
            'quantity' => (string) ($item['quantity'] ?? '1'),
            'unit_price_krw' => (string) ($item['unit_price_krw'] ?? $item['amount_krw'] ?? 0),
            'amount_krw' => (string) ($item['amount_krw'] ?? 0),
            'notes' => (string) ($item['notes'] ?? ''),
        ], $this->orderDetails['items'] ?? []);
        if ($this->items === []) {
            $this->items[] = [
                'project_name' => (string) $this->orderDetails['project_name'],
                'treatment_project_id' => (string) ($this->orderDetails['treatment_project_id'] ?? ''),
                'specification' => '',
                'quantity' => '1',
                'unit_price_krw' => (string) $this->orderDetails['amount_krw'],
                'amount_krw' => (string) $this->orderDetails['amount_krw'],
                'notes' => '',
            ];
        }
        $this->expectedUpdatedAt = (string) ($this->orderDetails['updated_at'] ?? '');
        $this->refreshCommissionPreview($workspace);
    }

    public function updatedOccurredOn(OrderManagementWorkspace $workspace): void
    {
        $this->refreshCommissionPreview($workspace);
    }

    public function updatedItems(OrderManagementWorkspace $workspace): void
    {
        $this->refreshCommissionPreview($workspace);
    }

    public function addItem(): void
    {
        $this->items[] = [
            'project_name' => '',
            'treatment_project_id' => '',
            'specification' => '',
            'quantity' => '1',
            'unit_price_krw' => '0',
            'amount_krw' => '0',
            'notes' => '',
        ];
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) <= 1 || ! array_key_exists($index, $this->items)) {
            return;
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function selectTreatmentProject(int $index): void
    {
        if (! isset($this->items[$index])) {
            return;
        }
        $projectId = (int) $this->items[$index]['treatment_project_id'];
        $project = collect($this->options['treatment_projects'] ?? [])->first(
            fn (array $option): bool => (int) $option['id'] === $projectId,
        );
        if ($project !== null) {
            $this->items[$index]['project_name'] = (string) $project['name'];
        }
    }

    public function save(OrderManagementWorkspace $workspace): void
    {
        $this->validate([
            'institutionId' => ['required', 'integer'],
            'agentId' => ($this->orderDetails['source_type'] ?? 'agent') === 'direct'
                ? ['nullable', 'integer']
                : ['required', 'integer'],
            'occurredOn' => [$this->orderDetails['status'] === 'completed' ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.project_name' => ['required', 'string', 'max:255'],
            'items.*.treatment_project_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price_krw' => ['required', 'integer', 'min:0'],
            'items.*.amount_krw' => ['required', 'integer', 'min:0'],
            'items.*.specification' => ['nullable', 'string', 'max:1000'],
            'items.*.notes' => ['nullable', 'string', 'max:5000'],
            'reason' => ['required', 'string', 'max:2000'],
            'translatorName' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $items = array_map(static fn (array $item): array => [
            'project_name' => (string) $item['project_name'],
            'treatment_project_id' => $item['treatment_project_id'] === '' ? null : (int) $item['treatment_project_id'],
            'specification' => $item['specification'] === '' ? null : $item['specification'],
            'quantity' => (string) $item['quantity'],
            'unit_price_krw' => (int) $item['unit_price_krw'],
            'amount_krw' => (int) $item['amount_krw'],
            'notes' => $item['notes'] === '' ? null : $item['notes'],
        ], $this->items);
        $firstItem = $items[0];

        try {
            $workspace->updatePending(new OrderUpdateData(
                orderId: $this->orderId,
                institutionId: (int) $this->institutionId,
                agentId: $this->agentId === '' ? null : (int) $this->agentId,
                projectName: $firstItem['project_name'],
                amountKrw: array_sum(array_column($items, 'amount_krw')),
                translatorName: $this->translatorName === '' ? null : $this->translatorName,
                notes: $this->notes === '' ? null : $this->notes,
                treatmentProjectId: $firstItem['treatment_project_id'],
                translatorLanguageId: $this->translatorLanguageId === '' ? null : (int) $this->translatorLanguageId,
                translatorLanguageName: null,
                occurredOn: $this->occurredOn === '' ? null : CarbonImmutable::createFromFormat('!Y-m-d', $this->occurredOn),
                items: $items,
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

    public function render(): View
    {
        return view('livewire.orders.order-edit', ['order' => $this->orderDetails])->title(__('orders.edit_title'));
    }

    private function refreshCommissionPreview(OrderManagementWorkspace $workspace): void
    {
        $this->commissionPreview = null;
        $this->commissionPreviewError = '';
        if (($this->orderDetails['status'] ?? null) !== 'completed' || ($this->orderDetails['source_type'] ?? null) !== 'agent') {
            return;
        }

        $commission = $this->orderDetails['financial']['commission'] ?? null;
        if (! is_array($commission) || ($commission['snapshot_valid'] ?? false) !== true) {
            $this->commissionPreviewError = __('settlements.errors.commission_snapshot_invalid');

            return;
        }
        if ($this->occurredOn === '') {
            return;
        }

        $rate = [
            'rate_bps' => (int) $commission['rate_bps'],
            'effective_month' => (string) ($commission['effective_month'] ?? ''),
            'rule_source' => (string) ($commission['rule_source'] ?? ''),
        ];
        $dateChanged = $this->occurredOn !== (string) ($this->orderDetails['occurred_on'] ?? '');
        if ($dateChanged) {
            try {
                $rate = $workspace->previewCommissionCorrection(
                    $this->orderId,
                    CarbonImmutable::createFromFormat('!Y-m-d', $this->occurredOn),
                );
            } catch (DomainException $exception) {
                $this->commissionPreviewError = $exception->getMessage();

                return;
            }
        }

        $newAmount = 0;
        foreach ($this->items as $item) {
            if (! is_numeric($item['amount_krw']) || (int) $item['amount_krw'] < 0) {
                $this->commissionPreviewError = __('orders.errors.order_items_invalid');

                return;
            }
            $newAmount += (int) $item['amount_krw'];
        }
        $estimatedCommission = BigDecimal::of($newAmount)
            ->multipliedBy((int) $rate['rate_bps'])
            ->dividedBy(10000, 0, RoundingMode::HalfUp)
            ->toInt();
        $oldCommission = (int) $commission['amount_krw'];

        $this->commissionPreview = [
            'old_amount_krw' => (int) $this->orderDetails['amount_krw'],
            'new_amount_krw' => $newAmount,
            'rate_bps' => (int) $rate['rate_bps'],
            'rule_source' => (string) $rate['rule_source'],
            'rule_source_label' => (string) __('settlements.rate_sources.'.(string) $rate['rule_source']),
            'effective_month' => (string) $rate['effective_month'],
            'old_commission_krw' => $oldCommission,
            'new_commission_krw' => $estimatedCommission,
            'commission_delta_krw' => $estimatedCommission - $oldCommission,
            'date_changed' => $dateChanged,
        ];
    }
}
