<?php

namespace App\Modules\Order\Presentation\Livewire;

use App\Modules\Order\Application\Services\DailyOrderWorkspace;
use App\Modules\Order\Application\Services\OrderManagementWorkspace;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('订单详情')]
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

    public function complete(DailyOrderWorkspace $workspace): void
    {
        abort_unless($this->orderDetails['status'] === 'pending' && $this->orderDetails['deleted_at'] === null, 403);
        try {
            $workspace->complete($this->orderId, CarbonImmutable::now('Asia/Shanghai'), (int) Auth::id(), request()->ip());
        } catch (DomainException $exception) {
            $this->addError('action', $exception->getMessage());

            return;
        }
        session()->flash('status', '订单已完成，推广费与术后提醒已同步固化。');
        $this->load(app(OrderManagementWorkspace::class));
    }

    public function cancel(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->cancel($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已取消。', $workspace);
    }

    public function reopen(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->reopen($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已重新打开。', $workspace);
    }

    public function softDelete(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->runAction(fn (): int => $workspace->softDelete($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已移入回收站。', $workspace);
    }

    public function restore(OrderManagementWorkspace $workspace): void
    {
        $this->requireAdmin();
        $this->runAction(fn (): int => $workspace->restore($this->orderId, (int) Auth::id(), request()->ip()), '订单已从回收站恢复。', $workspace);
    }

    public function render(): View
    {
        return view('livewire.orders.order-detail', ['order' => $this->orderDetails]);
    }

    public function changeStatus(DailyOrderWorkspace $dailyOrders, OrderManagementWorkspace $workspace): void
    {
        $target = $this->statusSelection;

        if ($target === 'completed') {
            $this->complete($dailyOrders);

            return;
        }

        if ($target === 'cancelled') {
            $this->requireAdmin();
            $this->validate(['reason' => ['required', 'string', 'max:1000']]);
            $this->runAction(fn (): int => $workspace->cancel($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已取消', $workspace);

            return;
        }

        if ($target === 'pending') {
            $this->requireAdmin();
            $this->validate(['reason' => ['required', 'string', 'max:1000']]);
            if ($this->orderDetails['status'] === 'completed') {
                $this->runAction(fn (): int => $workspace->rollbackCompleted($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已受控回退至待完成。', $workspace);

                return;
            }
            $this->runAction(fn (): int => $workspace->reopen($this->orderId, (int) Auth::id(), $this->reason, request()->ip()), '订单已重新打开', $workspace);

            return;
        }

        $this->addError('statusSelection', '请选择有效的订单状态。');
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
            $this->addError('action', $exception->getMessage());

            return;
        }
        $this->reset('reason');
        session()->flash('status', $message);
        $this->load($workspace);
    }
}
