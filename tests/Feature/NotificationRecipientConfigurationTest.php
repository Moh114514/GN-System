<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Application\Jobs\SendDingTalkNotification;
use App\Modules\Config\Infrastructure\Models\NotificationDelivery;
use App\Modules\Config\Infrastructure\Models\NotificationRecipientConfig;
use App\Modules\Config\Presentation\Livewire\NotificationRecipientConfiguration;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationRecipientConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_users_cannot_be_selected_as_dingtalk_recipients(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $unbound = User::factory()->create(['name' => '未绑定负责人']);

        $response = $this->actingAs($admin)->get(route('configuration.notifications'))->assertOk();
        self::assertMatchesRegularExpression('/value="'.$unbound->id.'"[^>]*disabled/', $response->getContent());

        Livewire::actingAs($admin)
            ->test(NotificationRecipientConfiguration::class)
            ->set('dingtalkUserIds', [$unbound->id])
            ->call('save')
            ->assertHasErrors('dingtalkUserIds');

        $this->assertDatabaseMissing('notification_recipient_configs', [
            'user_id' => $unbound->id,
            'channel' => 'dingtalk',
        ]);
    }

    public function test_dingtalk_delivery_is_queued_and_records_sent_status(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        $user = User::factory()->create(['dingtalk_user_id' => 'dt-user-1']);
        NotificationRecipientConfig::query()->create([
            'event_type' => 'agent_grade_adjustment',
            'user_id' => $user->id,
            'channel' => 'dingtalk',
            'enabled' => true,
        ]);
        Queue::fake();
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);

        app(NotificationRecipientGateway::class)->notify('agent_grade_adjustment', 'settlement:1', '等级调整', '请审核', null);

        $delivery = NotificationDelivery::query()->firstOrFail();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(['dt-user-1'], $delivery->recipients);
        Queue::assertPushed(SendDingTalkNotification::class, fn (SendDingTalkNotification $job): bool => $job->deliveryId === $delivery->id);

        (new SendDingTalkNotification($delivery->id))->handle(app(StaffNotificationSender::class));

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        Http::assertSent(fn ($request): bool => $request->data()['at']['atUserIds'] === ['dt-user-1']);
    }

    public function test_failed_dingtalk_delivery_is_recorded_and_can_be_requeued(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        $user = User::factory()->create(['dingtalk_user_id' => 'dt-user-2']);
        NotificationRecipientConfig::query()->create([
            'event_type' => 'agent_grade_adjustment',
            'user_id' => $user->id,
            'channel' => 'dingtalk',
            'enabled' => true,
        ]);
        Queue::fake();
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 500, 'errmsg' => 'failed'], 500)]);

        app(NotificationRecipientGateway::class)->notify('agent_grade_adjustment', 'settlement:2', '等级调整', '请审核', null);
        $delivery = NotificationDelivery::query()->firstOrFail();

        try {
            (new SendDingTalkNotification($delivery->id))->handle(app(StaffNotificationSender::class));
            self::fail('Expected DingTalk delivery to fail.');
        } catch (\Throwable) {
            // The queue worker must receive the exception so Laravel can retry it.
        }

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'status' => 'failed',
            'attempts' => 1,
        ]);

        app(NotificationRecipientGateway::class)->notify('agent_grade_adjustment', 'settlement:2', '等级调整', '请审核', null);
        $this->assertDatabaseHas('notification_deliveries', ['id' => $delivery->id, 'status' => 'queued']);
        Queue::assertPushed(SendDingTalkNotification::class, 2);
    }
}
