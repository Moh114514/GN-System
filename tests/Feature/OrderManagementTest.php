<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Presentation\Livewire\OrderCenter;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_can_open_order_center_from_primary_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('orders.index'))
            ->assertOk()
            ->assertSee('订单管理')
            ->assertSee('新建订单')
            ->assertSee('href="'.route('orders.index').'"', false)
            ->assertDontSee('功能将在后续阶段开放');
    }

    public function test_order_center_creates_filters_and_completes_direct_order_with_audit_trail(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $institution = Institution::query()->firstOrFail();
        $source = DirectSalesSource::query()->create([
            'code' => 'WEB',
            'name' => '官网直销',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'code' => 'WEB-000001',
            'name' => '订单中心客户',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $source->id,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(OrderCenter::class)
            ->call('openCreate')
            ->set('customerSearch', '订单中心')
            ->assertSee('订单中心客户')
            ->call('selectCustomer', $customer->id)
            ->assertSet('channel', 'direct')
            ->assertSet('directSalesSourceId', (string) $source->id)
            ->set('institutionId', (string) $institution->id)
            ->set('projectName', '皮肤管理')
            ->set('amountKrw', '880000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('皮肤管理')
            ->set('search', '不存在的客户')
            ->assertDontSee('皮肤管理')
            ->set('search', '订单中心客户')
            ->assertSee('皮肤管理')
            ->set('search', '')
            ->set('statusFilter', 'completed')
            ->assertDontSee('皮肤管理')
            ->set('statusFilter', 'pending')
            ->assertSee('皮肤管理');

        $order = Order::query()->firstOrFail();

        Livewire::actingAs($user)
            ->test(OrderCenter::class)
            ->call('complete', $order->id)
            ->assertHasNoErrors()
            ->assertSee('已完成');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
            'amount_krw' => 880000,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'order',
            'subject_id' => $order->id,
            'event' => 'created',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'order',
            'subject_id' => $order->id,
            'event' => 'completed',
        ]);
        $this->assertDatabaseMissing('order_commissions', ['order_id' => $order->id]);
    }
}
