<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Order\Presentation\Livewire\OrderCenter;
use App\Modules\Order\Presentation\Livewire\OrderDetail;
use App\Modules\Order\Presentation\Livewire\OrderEdit;
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

    public function test_pending_order_can_be_edited_and_detail_page_has_parent_navigation(): void
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
            'code' => 'WEB-000002',
            'name' => '订单详情客户',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $source->id,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(OrderCenter::class)
            ->call('openCreate')
            ->call('selectCustomer', $customer->id)
            ->set('institutionId', (string) $institution->id)
            ->set('projectName', '初始项目')
            ->set('amountKrw', '100000')
            ->call('save');

        $order = Order::query()->firstOrFail();
        $this->actingAs($user)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('订单详情')
            ->assertSee('返回订单管理')
            ->assertSee('href="'.route('orders.index').'"', false);

        Livewire::actingAs($user)
            ->test(OrderEdit::class, ['order' => $order->id])
            ->set('projectName', '更新后的项目')
            ->set('amountKrw', '120000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'project_name' => '更新后的项目',
            'amount_krw' => 120000,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'order',
            'subject_id' => $order->id,
            'event' => 'updated',
        ]);
    }

    public function test_admin_can_cancel_soft_delete_restore_and_reopen_pending_order(): void
    {
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $institution = Institution::query()->firstOrFail();
        $source = DirectSalesSource::query()->create([
            'code' => 'WEB',
            'name' => '官网直销',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'code' => 'WEB-000003',
            'name' => '订单生命周期客户',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $source->id,
            'owner_id' => $user->id,
        ]);
        $order = Order::query()->create([
            'customer_id' => $customer->id,
            'institution_id' => $institution->id,
            'channel' => 'direct',
            'direct_sales_source_id' => $source->id,
            'project_name' => '生命周期项目',
            'amount_krw' => 200000,
            'status' => 'pending',
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('reason', '普通用户不应执行取消')
            ->call('cancel')
            ->assertStatus(403);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->set('reason', '客户主动取消')
            ->call('cancel')
            ->assertHasNoErrors()
            ->set('reason', '取消订单归档')
            ->call('softDelete')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'cancellation_reason' => '客户主动取消',
            'deletion_reason' => '取消订单归档',
        ]);

        Livewire::actingAs($admin)
            ->test(OrderDetail::class, ['order' => $order->id])
            ->call('restore')
            ->assertHasNoErrors()
            ->set('reason', '确认客户仍需继续办理')
            ->call('reopen')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending',
            'deleted_at' => null,
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'cancelled']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'deleted']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'restored']);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'order', 'subject_id' => $order->id, 'event' => 'reopened']);
    }
}
