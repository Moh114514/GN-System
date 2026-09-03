<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OrderDetail extends Component
{
    public int $orderId;

    /** @var array<string, mixed> */
    public array $orderDetails = [];

    public string $reason = '';

    public string $statusSelection = '';

    public function mount(int $order, OrderManagementWorkspace $workspace): void
    {
        $this->orderId = $order;
        $this->load($workspace);
    }

    public function cancel(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->cancel($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.cancelled', $workspace);
    }

    public function reopen(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->reopen($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.reopened', $workspace);
    }

    public function softDelete(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->softDelete($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.soft_deleted', $workspace);
    }

    public function restore(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->runAction(fn (): int => $workspace->restore($this->orderId, (int) Auth::id(), request()->ip()), 'orders.messages.restored', $workspace);
    }

    public function render(): View
    {
        return view('livewire.orders.order-detail', ['order' => $this->orderDetails])->title(__('orders.detail_title'));
    }

    public function changeStatus(OrderManagementWorkspace $workspace): void
    {
        $target = $this->statusSelection;

        if ($target === 'completed') {
            $this->addError('statusSelection', __('orders.errors.manual_completion_disabled'));

            return;
        }

        if ($target === 'cancelled') {
            $this->requireAdmin();
            $this->validate(['reason' => ['required', 'string', 'max:1000']]);
            $this->runAction(fn (): int => $workspace->cancel($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.cancelled', $workspace);

            return;
        }

        if ($target === 'pending') {
            $this->requireAdmin();
            $this->validate(['reason' => ['required', 'string', 'max:1000']]);
            if ($this->orderDetails['status'] === 'completed') {
                $this->runAction(fn (): int => $workspace->rollbackCompleted($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.rolled_back', $workspace);

                return;
            }
            $this->runAction(fn (): int => $workspace->reopen($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), 'orders.messages.reopened', $workspace);

            return;
        }

        $this->addError('statusSelection', __('orders.errors.invalid_status'));
    }

    private function load(OrderManagementWorkspace $workspace): void
    {
        $this->orderDetails = $workspace->detail($this->orderId, (bool) Auth::user()?->is_super_admin);
        $this->statusSelection = (string) $this->orderDetails['status'];
    }

    private function requireAdmin(): void
    {
        abort_unless((bool) Auth::user()?->is_super_admin, 403);
    }

    /** @param callable(): int $action */
    private function runAction(callable $action, string $message, OrderManagementWorkspace $workspace): void
    {
        try {
            $action();
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: __('orders.errors.unexpected', ['message' => $exception->getMessage()]));

            return;
        }
        $this->reset('reason');
        Flux::toast(variant: 'success', text: __($message));
        $this->load($workspace);
    }
}
