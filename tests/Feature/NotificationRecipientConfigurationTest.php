<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use App\Modules\Config\Application\Jobs\SendDingTalkNotification;
use App\Modules\Config\Infrastructure\Models\NotificationDelivery;
use App\Modules\Config\Infrastructure\Models\NotificationRecipientConfig;
use App\Modules\Config\Presentation\Livewire\NotificationRecipientConfiguration;
use App\Modules\Config\Presentation\Livewire\UserManagement;
use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationRecipientConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_users_cannot_be_selected_as_dingtalk_recipients(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $invalidType = User::factory()->create([
            'name' => 'Invalid DingTalk Binding',
            'dingtalk_mention_type' => 'nickname',
            'dingtalk_mention_value' => 'nickname-binding',
        ]);
        $unbound = User::factory()->create(['name' => '未绑定负责人']);

        $response = $this->actingAs($admin)->get(route('configuration.users-and-notifications', ['tab' => 'notifications']))->assertOk();
        self::assertMatchesRegularExpression('/value="'.$unbound->id.'"[^>]*disabled/', $response->getContent());
        self::assertMatchesRegularExpression('/value="'.$invalidType->id.'"[^>]*disabled/', $response->getContent());

        Livewire::actingAs($admin)
            ->test(NotificationRecipientConfiguration::class)
            ->set('dingtalkUserIds', [$unbound->id, $invalidType->id])
            ->call('save')
            ->assertHasErrors('dingtalkUserIds');

        $this->assertDatabaseMissing('notification_recipient_configs', [
            'user_id' => $unbound->id,
            'channel' => 'dingtalk',
        ]);
        $this->assertDatabaseMissing('notification_recipient_configs', [
            'user_id' => $invalidType->id,
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
        $user = User::factory()->create([
            'dingtalk_mention_type' => 'user_id',
            'dingtalk_mention_value' => 'dt-user-1',
        ]);
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
        $this->assertSame([['type' => 'user_id', 'value' => 'dt-user-1']], $delivery->recipients);
        Queue::assertPushed(SendDingTalkNotification::class, fn (SendDingTalkNotification $job): bool => $job->deliveryId === $delivery->id);

        (new SendDingTalkNotification($delivery->id))->handle(app(StaffNotificationSender::class));

        $this->assertDatabaseHas('notification_deliveries', [
            'id' => $delivery->id,
            'status' => 'sent',
            'attempts' => 1,
        ]);
        Http::assertSent(fn ($request): bool => $request->data()['at'] === [
            'atUserIds' => ['dt-user-1'],
            'isAtAll' => false,
        ] && str_contains((string) $request->data()['markdown']['text'], '@dt-user-1'));
    }

    public function test_dingtalk_mention_values_are_trimmed_and_type_validated(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $sender = app(StaffNotificationSender::class);

        // The test can exercise the bot webhook only; DingTalk User ID existence is not remotely validated here.
        $sparseRecipients = [];
        $sparseRecipients[3] = ['type' => 'user_id', 'value' => ' employee/id+1 '];
        $sender->send('提醒', '正文', null, $sparseRecipients);
        Http::assertSent(fn ($request): bool => $request->data()['at']['atUserIds'] === ['employee/id+1']
            && $request->data()['at']['isAtAll'] === false
            && str_contains((string) $request->data()['markdown']['text'], '@employee/id+1'));

        try {
            $sender->send('提醒', '正文', null, [['type' => 'nickname', 'value' => '张三']]);
            self::fail('Expected an invalid DingTalk mention type to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(__('auth.errors.dingtalk_mention_type_invalid'), $exception->getMessage());
        }

        try {
            $sender->send('提醒', '正文', null, [['type' => 'mobile', 'value' => '   ']]);
            self::fail('Expected a blank DingTalk mention value to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(__('auth.errors.dingtalk_mention_value_required'), $exception->getMessage());
        }

        try {
            $sender->send('鎻愰啋', '姝ｆ枃', null, [['type' => 'mobile', 'value' => 'not-a-phone']]);
            self::fail('Expected a malformed DingTalk mobile value to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(__('auth.errors.dingtalk_mention_value_invalid'), $exception->getMessage());
        }

        try {
            $sender->send('鎻愰啋', "姝ｆ枃\n", null, [['type' => 'user_id', 'value' => "safe\nid"]]);
            self::fail('Expected a control character in a DingTalk User ID to be rejected.');
        } catch (\DomainException $exception) {
            $this->assertSame(__('auth.errors.dingtalk_mention_value_invalid'), $exception->getMessage());
        }

        $this->expectException(\DomainException::class);
        $sender->send('提醒', '正文', null, [['type' => 'mobile', 'value' => str_repeat('x', 256)]]);
    }

    public function test_dingtalk_sender_supports_mobile_and_user_id_mentions_together(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);

        app(StaffNotificationSender::class)->send('提醒', '正文', null, [
            ['type' => 'mobile', 'value' => '13982227918'],
            ['type' => 'user_id', 'value' => 'enterprise-user-1'],
        ]);

        Http::assertSent(fn ($request): bool => $request->data()['at'] === [
            'atMobiles' => ['13982227918'],
            'atUserIds' => ['enterprise-user-1'],
            'isAtAll' => false,
        ]
            && str_contains((string) $request->data()['markdown']['text'], '@13982227918')
            && str_contains((string) $request->data()['markdown']['text'], '@enterprise-user-1'));
    }

    public function test_user_management_saves_dingtalk_mention_type_and_value(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $user = User::factory()->create();

        Livewire::actingAs($admin)
            ->test(UserManagement::class)
            ->set("dingtalkMentionTypes.{$user->id}", 'mobile')
            ->set("dingtalkMentionValues.{$user->id}", ' 13982227918 ')
            ->call('saveDingTalkMention', $user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dingtalk_mention_type' => 'mobile',
            'dingtalk_mention_value' => '13982227918',
        ]);
    }

    public function test_failed_dingtalk_delivery_is_recorded_and_can_be_requeued(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        $user = User::factory()->create([
            'dingtalk_mention_type' => 'mobile',
            'dingtalk_mention_value' => '13982227918',
        ]);
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

    public function test_dingtalk_mention_migration_round_trips_user_id_bindings(): void
    {
        $user = User::factory()->create([
            'dingtalk_mention_type' => 'user_id',
            'dingtalk_mention_value' => 'legacy-user-1',
        ]);
        $migration = require database_path('migrations/2026_08_20_000100_add_dingtalk_mention_configuration_to_users.php');

        $migration->down();

        self::assertTrue(Schema::hasColumn('users', 'dingtalk_user_id'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dingtalk_user_id' => 'legacy-user-1',
        ]);

        $migration->up();

        self::assertFalse(Schema::hasColumn('users', 'dingtalk_user_id'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dingtalk_mention_type' => 'user_id',
            'dingtalk_mention_value' => 'legacy-user-1',
        ]);
    }

    public function test_dingtalk_mention_migration_refuses_rollback_with_mobile_bindings(): void
    {
        $user = User::factory()->create([
            'dingtalk_mention_type' => 'mobile',
            'dingtalk_mention_value' => '13982227918',
        ]);
        $migration = require database_path('migrations/2026_08_20_000100_add_dingtalk_mention_configuration_to_users.php');

        try {
            $migration->down();
            self::fail('Expected the migration rollback to refuse dropping mobile bindings.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('mobile bindings', $exception->getMessage());
        }

        self::assertTrue(Schema::hasColumn('users', 'dingtalk_mention_type'));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'dingtalk_mention_type' => 'mobile',
            'dingtalk_mention_value' => '13982227918',
        ]);
    }

    public function test_legacy_string_delivery_recipients_are_sent_as_user_ids(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => '',
        ]);
        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $delivery = NotificationDelivery::query()->create([
            'event_type' => 'agent_grade_adjustment',
            'event_key' => 'legacy:settlement:1',
            'channel' => 'dingtalk',
            'title' => '提醒',
            'body' => '正文',
            'link' => null,
            'recipients' => ['legacy-user-1'],
            'status' => 'queued',
        ]);

        (new SendDingTalkNotification($delivery->id))->handle(app(StaffNotificationSender::class));

        Http::assertSent(fn ($request): bool => $request->data()['at'] === [
            'atUserIds' => ['legacy-user-1'],
            'isAtAll' => false,
        ]);
    }
}
