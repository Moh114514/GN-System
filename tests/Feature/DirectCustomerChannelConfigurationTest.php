<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Application\Data\CustomerProfileData;
use App\Modules\Customer\Application\Services\CustomerDirectory;
use App\Modules\Customer\Application\Services\CustomerProfileManager;
use App\Modules\Customer\Application\Services\DirectCustomerChannelManager;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectCustomerChannel;
use App\Modules\Customer\Presentation\Livewire\DirectCustomerChannelConfiguration;
use App\Modules\Customer\Presentation\Livewire\DirectCustomerForm;
use App\Modules\Order\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DirectCustomerChannelConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->actingAs($this->admin);
    }

    public function test_channels_are_embedded_in_the_existing_configuration_page_with_parent_navigation_and_localization(): void
    {
        $this->get(route('configuration.direct-customer'))->assertOk()
            ->assertSee('直客业务配置')->assertSee('直客渠道')->assertSee('微信')
            ->assertSee('保存提成规则')->assertSee('返回配置中心')
            ->assertSee('href="'.route('configuration.index').'"', false)
            ->assertSeeLivewire(DirectCustomerChannelConfiguration::class);
        $this->admin->update(['preferred_locale' => 'ko_KR']);
        $this->get(route('configuration.direct-customer'))->assertOk()
            ->assertSee('직접 고객 업무 설정')->assertSee('직접 고객 유입 채널')
            ->assertSee('채널 저장')->assertDontSee('config.direct_customer.channels.');
    }

    public function test_admin_can_create_edit_sort_and_toggle_channels_with_localized_audit_and_stable_codes(): void
    {
        $this->freezeTime();
        $component = Livewire::test(DirectCustomerChannelConfiguration::class)
            ->set('code', 'partner_event')->set('name', '合作活动')->set('sortOrder', '0')
            ->call('save')->assertHasNoErrors()->assertSet('channelId', null);
        $channel = DirectCustomerChannel::query()->where('code', 'partner_event')->firstOrFail();
        $this->assertSame($channel->id, $component->get('channels')[0]['id']);
        $component->call('edit', $channel->id)->set('name', '品牌合作活动')->set('sortOrder', '5')
            ->call('save')->assertHasNoErrors()->call('toggle', $channel->id);
        $this->assertDatabaseHas('direct_customer_channels', [
            'id' => $channel->id, 'code' => 'partner_event', 'name' => '品牌合作活动', 'sort_order' => 5, 'is_active' => false,
        ]);
        $logs = Activity::query()->where('log_name', 'direct-customer-channel')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame($this->admin->id, (int) $logs[0]->causer_id);
        $this->assertNull($logs[0]->properties['before']);
        $this->assertSame('合作活动', $logs[1]->properties['before']['name']);
        $this->assertSame('品牌合作活动', $logs[1]->properties['after']['name']);
        $this->assertFalse($logs[2]->properties['after']['is_active']);
        app()->setLocale('ko_KR');
        $trail = app(AuditRecorder::class)->trail($channel, 'direct-customer-channel');
        $this->assertCount(3, $trail);
        $statusEntries = array_values(array_filter($trail, fn ($entry): bool => $entry->event === 'status_changed'));
        $this->assertCount(1, $statusEntries);
        $this->assertSame('직접 고객 유입 채널 상태가 변경되었습니다', $statusEntries[0]->description);
        $this->assertSame($logs[2]->properties['before'], $statusEntries[0]->properties['before']);
        $this->assertSame($logs[2]->properties['after'], $statusEntries[0]->properties['after']);
        $component->call('toggle', $channel->id);
        $this->assertTrue($channel->fresh()->is_active);
    }

    public function test_invalid_or_duplicate_codes_names_and_sort_values_are_rejected_without_writing(): void
    {
        foreach ([
            ['code', 'wechat'], ['code', 'Uppercase'], ['code', 'bad-code'], ['code', str_repeat('a', 33)],
            ['name', ''], ['name', str_repeat('名', 256)], ['sortOrder', '-1'], ['sortOrder', '32768'], ['sortOrder', '1.5'],
        ] as [$field, $value]) {
            Livewire::test(DirectCustomerChannelConfiguration::class)
                ->set('code', 'valid_channel')->set('name', '新渠道')->set('sortOrder', '0')
                ->set($field, $value)->call('save')->assertHasErrors([$field]);
        }
        $this->assertSame(8, DirectCustomerChannel::query()->count());
        $this->assertSame(0, Activity::query()->where('log_name', 'direct-customer-channel')->count());
    }

    public function test_existing_channel_code_cannot_be_changed_even_by_a_forged_livewire_input(): void
    {
        $channel = DirectCustomerChannel::query()->where('code', 'wechat')->firstOrFail();
        Livewire::test(DirectCustomerChannelConfiguration::class)->call('edit', $channel->id)
            ->set('code', 'renamed_wechat')->call('save')->assertHasErrors(['code']);
        $this->assertSame('wechat', $channel->fresh()->code);
        $this->assertSame(0, Activity::query()->where('log_name', 'direct-customer-channel')->count());
    }

    public function test_non_admin_roles_cannot_open_mount_read_or_mutate_channel_configuration(): void
    {
        $channel = DirectCustomerChannel::query()->firstOrFail();
        foreach ([UserRole::CustomerService, UserRole::BdManager, UserRole::DirectCustomerManager] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('configuration.direct-customer'))->assertForbidden();
            Livewire::test(DirectCustomerChannelConfiguration::class)->assertForbidden();
            foreach ([
                fn () => app(DirectCustomerChannelManager::class)->channels(),
                fn () => app(DirectCustomerChannelManager::class)->channel($channel->id),
                fn () => app(DirectCustomerChannelManager::class)->save(null, 'forbidden', '越权渠道', 0, null),
                fn () => app(DirectCustomerChannelManager::class)->toggle($channel->id, null),
            ] as $action) {
                try {
                    $action();
                    $this->fail('Non-admin access must be rejected.');
                } catch (HttpException $exception) {
                    $this->assertSame(403, $exception->getStatusCode());
                }
            }
        }
        $this->assertSame(8, DirectCustomerChannel::query()->count());
        $this->assertTrue($channel->fresh()->is_active);
    }

    public function test_permission_is_rechecked_when_a_previously_mounted_admin_component_is_used_by_another_role(): void
    {
        $component = Livewire::test(DirectCustomerChannelConfiguration::class)
            ->set('code', 'no_longer_allowed')->set('name', '越权渠道');
        $this->actingAs(User::factory()->create(['role' => UserRole::DirectCustomerManager]));
        $component->call('save')->assertForbidden();
        $this->assertDatabaseMissing('direct_customer_channels', ['code' => 'no_longer_allowed']);
    }

    public function test_disabling_a_used_channel_preserves_customer_and_order_snapshots_and_allows_retaining_it_during_edits(): void
    {
        $channel = DirectCustomerChannel::query()->where('code', 'wechat')->firstOrFail();
        $customer = $this->createCustomer((int) $channel->id);
        $snapshot = ['source' => 'direct', 'channel' => ['id' => $channel->id, 'code' => 'wechat', 'name' => '微信']];
        $order = Order::query()->create([
            'customer_id' => $customer->id, 'institution_id' => Institution::query()->firstOrFail()->id,
            'source_type' => 'direct', 'agent_id' => null, 'owner_id' => $customer->owner_id,
            'project_name' => '渠道历史测试', 'amount_krw' => 10000, 'status' => 'pending',
            'occurred_on' => CarbonImmutable::now()->toDateString(), 'business_attribution_snapshot' => $snapshot,
        ]);
        Livewire::test(DirectCustomerChannelConfiguration::class)->call('toggle', $channel->id)
            ->call('edit', $channel->id)->set('name', '微信私域')->call('save')->assertHasNoErrors();
        $this->assertSame($channel->id, $customer->fresh()->direct_channel_id);
        $this->assertSame($snapshot, $order->fresh()->business_attribution_snapshot);
        $this->assertNotContains($channel->id, array_column(app(CustomerDirectory::class)->directCustomerOptions()['direct_channels'], 'id'));
        $this->actingAs(User::query()->findOrFail($customer->owner_id));
        Livewire::test(DirectCustomerForm::class, ['customer' => $customer->id])
            ->assertSee('微信私域')->set('notes', '停用后仍可更新既有客户')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('direct-customers.show', $customer->id));
        $this->assertSame($channel->id, $customer->fresh()->direct_channel_id);
        $this->assertSame($snapshot, $order->fresh()->business_attribution_snapshot);
        $otherChannel = DirectCustomerChannel::query()->where('code', 'douyin')->firstOrFail();
        $otherChannel->update(['is_active' => false]);
        Livewire::test(DirectCustomerForm::class, ['customer' => $customer->id])
            ->set('channelId', (string) $otherChannel->id)->call('save')->assertHasErrors(['channelId']);
        Livewire::test(DirectCustomerForm::class)->set('channelId', (string) $channel->id)
            ->call('save')->assertHasErrors(['channelId']);
    }

    public function test_application_layer_rejects_new_customers_using_an_inactive_channel_and_seeding_preserves_configuration(): void
    {
        $channel = DirectCustomerChannel::query()->where('code', 'wechat')->firstOrFail();
        app(DirectCustomerChannelManager::class)->save($channel->id, 'wechat', '微信私域', 0, null);
        app(DirectCustomerChannelManager::class)->toggle($channel->id, null);
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->assertDatabaseHas('direct_customer_channels', ['id' => $channel->id, 'name' => '微信私域', 'sort_order' => 0, 'is_active' => false]);
        $this->expectException(ValidationException::class);
        $this->createCustomer((int) $channel->id);
    }

    private function createCustomer(int $channelId): Customer
    {
        $owner = User::factory()->create(['role' => UserRole::DirectCustomerManager]);
        $manager = app(CustomerProfileManager::class);
        $id = $manager->create(
            profile: new CustomerProfileData(
                name: '渠道测试直客', gender: null, birthDate: CarbonImmutable::parse('1990-01-01'), sourceAgentId: null,
                contactValue: '13800000001', identityDocument: 'CHANNEL-PASSPORT-1', projectIntention: '皮肤管理',
                notes: null, sourceType: 'direct', directChannelId: $channelId,
            ),
            institutionId: Institution::query()->firstOrFail()->id,
            arrivalAt: CarbonImmutable::now()->addDay()->setTime(10, 0), translatorName: null,
            actorId: $this->admin->id, ownerId: $owner->id, confirmedCode: $manager->previewDirectCode(),
            automaticCode: true, ipAddress: null,
        );

        return Customer::query()->findOrFail($id);
    }
}
