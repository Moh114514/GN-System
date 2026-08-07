<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Customer\Infrastructure\Models\Customer;
use App\Modules\Customer\Infrastructure\Models\DirectSalesSource;
use App\Modules\Order\Infrastructure\Models\Appointment;
use App\Modules\Order\Infrastructure\Models\Order;
use App\Modules\Reminder\Application\Data\CompletedTreatmentData;
use App\Modules\Reminder\Application\Services\DatabaseOrderReminderReader;
use App\Modules\Reminder\Application\Services\DatabaseTreatmentReminderGateway;
use App\Modules\Reminder\Application\Services\ReminderContentPresenter;
use App\Modules\Reminder\Application\Services\ReminderNotificationDispatcher;
use App\Modules\Reminder\Application\Services\ReminderNotifier;
use App\Modules\Reminder\Application\Services\ReminderRuleManager;
use App\Modules\Reminder\Application\Services\ReminderScheduler;
use App\Modules\Reminder\Application\Services\ReminderWorkspace;
use App\Modules\Reminder\Infrastructure\Models\Reminder;
use App\Modules\Reminder\Infrastructure\Models\ReminderRule;
use App\Modules\Reminder\Infrastructure\Models\ReminderTemplate;
use App\Modules\Reminder\Jobs\SendReminderNotification;
use App\Modules\Reminder\Presentation\Livewire\ReminderCreate;
use Carbon\CarbonImmutable;
use Database\Seeders\PhaseTwoReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseFiveReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $other;

    private User $admin;

    private Customer $customer;

    private DirectSalesSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-01 08:00:00');
        $this->seed(PhaseTwoReferenceDataSeeder::class);
        $this->user = User::factory()->create(['name' => '负责客服']);
        $this->other = User::factory()->create(['name' => '其他客服']);
        $this->admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $this->source = DirectSalesSource::query()->create(['code' => 'REM', 'name' => '提醒测试', 'is_active' => true]);
        $this->customer = Customer::query()->create([
            'code' => 'REMIND-0001',
            'name' => '提醒测试客户',
            'birth_date' => '1990-08-02',
            'original_channel' => 'direct',
            'source_direct_sales_id' => $this->source->id,
            'owner_id' => $this->user->id,
            'project_intention' => '皮肤管理',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_completed_treatment_creates_five_idempotent_future_reminders(): void
    {
        $order = Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => Institution::query()->firstOrFail()->id,
            'channel' => 'direct',
            'direct_sales_source_id' => $this->source->id,
            'project_name' => '皮肤管理',
            'amount_krw' => 10000,
            'completed_on' => '2026-08-01',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $data = new CompletedTreatmentData(
            orderId: $order->id,
            customerId: $this->customer->id,
            projectName: '皮肤管理',
            completedOn: CarbonImmutable::parse('2026-08-01'),
            ownerId: $this->user->id,
            actorId: $this->user->id,
        );
        $gateway = app(DatabaseTreatmentReminderGateway::class);
        $gateway->schedule($data);
        $gateway->schedule($data);

        $this->assertDatabaseCount('reminders', 5);
        $this->assertDatabaseHas('reminders', ['customer_id' => $this->customer->id, 'reminder_type' => 'post_treatment', 'status' => 'pending']);
        $this->assertDatabaseCount('reminder_events', 5);
    }

    public function test_cancelled_post_treatment_reminders_reactivate_on_order_recompletion(): void
    {
        $order = Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => Institution::query()->firstOrFail()->id,
            'channel' => 'direct',
            'direct_sales_source_id' => $this->source->id,
            'project_name' => '提醒复活项目',
            'amount_krw' => 10000,
            'completed_on' => '2026-08-05',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        $data = new CompletedTreatmentData(
            orderId: $order->id,
            customerId: $this->customer->id,
            projectName: '提醒复活项目',
            completedOn: CarbonImmutable::parse('2026-08-05'),
            ownerId: $this->user->id,
            actorId: $this->user->id,
        );
        $gateway = app(DatabaseTreatmentReminderGateway::class);
        $gateway->schedule($data);
        $completed = Reminder::query()->where('order_id', $order->id)->where('reminder_type', 'post_treatment')->firstOrFail();
        $completed->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $this->user->id]);
        $gateway->cancelForOrder($order->id, $this->admin->id, '订单状态回退');

        $gateway->schedule($data);

        $this->assertSame(5, Reminder::query()->where('order_id', $order->id)->count());
        $this->assertSame('completed', $completed->refresh()->status);
        $this->assertSame(4, Reminder::query()->where('order_id', $order->id)->where('status', 'pending')->count());
        $this->assertDatabaseHas('reminder_events', ['event' => 'reactivated']);
    }

    public function test_scheduler_materializes_appointment_and_birthday_without_duplicates(): void
    {
        $institution = Institution::query()->firstOrFail();
        Appointment::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => $institution->id,
            'scheduled_at' => '2026-08-04 10:00:00',
            'owner_id' => $this->user->id,
            'status' => 'scheduled',
        ]);
        $scheduler = app(ReminderScheduler::class);
        $this->assertSame(3, $scheduler->materialize());
        $this->assertSame(0, $scheduler->materialize());
        $this->assertDatabaseHas('reminders', ['title' => '术前 3 天确认', 'assigned_to' => $this->user->id]);
        $this->assertDatabaseHas('reminders', ['title' => '今日到店接待确认']);
    }

    public function test_custom_reminder_visibility_lifecycle_and_recurrence(): void
    {
        $workspace = app(ReminderWorkspace::class);
        $id = $workspace->createCustom(
            customerId: $this->customer->id,
            assignedTo: $this->user->id,
            title: '自定义回访',
            dueAt: CarbonImmutable::now()->addHour(),
            notes: null,
            suggestion: null,
            recurrence: ['unit' => 'week', 'interval' => 1],
            templateId: null,
            actorId: $this->user->id,
        );
        $this->assertSame(1, $workspace->paginate($this->user, false)->total());
        $this->assertSame(0, $workspace->paginate($this->other, false)->total());
        $this->assertSame(1, $workspace->paginate($this->admin, false)->total());

        $workspace->snooze($id, CarbonImmutable::now()->addDay(), '客户改期', $this->user);
        $workspace->transfer($id, $this->other->id, $this->user);
        $workspace->complete($id, $this->other, '已电话回访');
        $this->assertDatabaseHas('reminders', ['id' => $id, 'status' => 'completed', 'completed_by' => $this->other->id]);
        $this->assertDatabaseHas('reminders', ['source_type' => 'custom', 'status' => 'pending']);
        $this->assertDatabaseHas('reminder_events', ['reminder_id' => $id, 'event' => 'transferred']);
    }

    public function test_dingtalk_notification_uses_queue_and_records_delivery(): void
    {
        config([
            'dingtalk.enabled' => true,
            'dingtalk.webhook_url' => 'https://oapi.dingtalk.com/robot/send?access_token=test',
            'dingtalk.secret' => 'secret',
        ]);
        $this->user->update(['preferred_locale' => 'ko_KR']);

        Http::fake(['oapi.dingtalk.com/*' => Http::response(['errcode' => 0, 'errmsg' => 'ok'])]);
        $reminder = Reminder::query()->create([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->user->id,
            'created_by' => $this->user->id,
            'source_type' => 'custom',
            'reminder_type' => 'custom',
            'title' => '到期提醒',
            'due_at' => now()->subMinute(),
            'status' => 'pending',
            'notification_status' => 'pending',
            'dedupe_key' => hash('sha256', 'dingtalk-test'),
        ]);
        Queue::fake();
        $this->assertSame(1, app(ReminderNotificationDispatcher::class)->dispatchDue());
        Queue::assertPushed(
            SendReminderNotification::class,
            fn (SendReminderNotification $job): bool => $job->locale === 'ko_KR',
        );
        (new SendReminderNotification($reminder->id, 'ko_KR'))->handle(app(ReminderNotifier::class));

        $this->assertDatabaseHas('reminders', ['id' => $reminder->id, 'notification_status' => 'sent']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'timestamp=') && str_contains($request->url(), 'sign='));
        Http::assertSent(fn ($request): bool => str_contains((string) $request->data()['markdown']['text'], '고객:'));
    }

    public function test_reminder_pages_follow_two_level_permissions_and_navigation(): void
    {
        $this->actingAs($this->user)->get(route('reminders.index'))->assertOk()->assertSee('主动提醒');
        $this->actingAs($this->user)->get(route('reminders.history'))
            ->assertOk()->assertSee('返回主动提醒')->assertSee('href="'.route('reminders.index').'"', false);
        $this->actingAs($this->user)->get(route('reminder-configuration.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('reminder-configuration.index'))
            ->assertOk()->assertSee('返回配置中心')->assertSee('href="'.route('configuration.index').'"', false);
    }

    public function test_korean_user_sees_localized_reminder_pages(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($user)->get(route('reminders.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('능동 알림')
            ->assertSee('알림 기록')
            ->assertDontSee('主动提醒');

        $this->actingAs($user)->get(route('reminders.history'))
            ->assertOk()
            ->assertSee('능동 알림으로 돌아가기')
            ->assertSee('과거 알림이 없습니다.');
    }

    public function test_system_templates_and_generated_reminders_are_projected_in_korean(): void
    {
        $manager = app(ReminderRuleManager::class);
        $manager->ensureSystemTemplates();
        $template = ReminderTemplate::query()->where('system_key', 'pre_visit_confirmation')->firstOrFail();
        $this->assertSame('术前确认', $template->name);

        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');
        $this->assertSame('시술 전 확인', app(ReminderContentPresenter::class)->template($template)['name']);
        App::setLocale($previousLocale);

        $order = Order::query()->create([
            'customer_id' => $this->customer->id,
            'institution_id' => Institution::query()->firstOrFail()->id,
            'channel' => 'direct',
            'direct_sales_source_id' => $this->source->id,
            'project_name' => '피부 관리',
            'amount_krw' => 10000,
            'completed_on' => '2026-08-01',
            'owner_id' => $this->user->id,
            'status' => 'completed',
        ]);
        app(DatabaseTreatmentReminderGateway::class)->schedule(new CompletedTreatmentData(
            orderId: $order->id,
            customerId: $this->customer->id,
            projectName: '피부 관리',
            completedOn: CarbonImmutable::parse('2026-08-01'),
            ownerId: $this->user->id,
            actorId: $this->user->id,
        ));
        $this->user->update(['preferred_locale' => 'ko_KR']);

        $generatedReminder = Reminder::query()->where('order_id', $order->id)->firstOrFail();
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');
        try {
            $generatedContent = app(ReminderContentPresenter::class)->reminder($generatedReminder);
            $this->assertNotSame((string) $generatedReminder->title, $generatedContent['title']);
            $this->assertNotSame((string) $generatedReminder->notes, $generatedContent['notes']);
            $orderReminder = app(DatabaseOrderReminderReader::class)->forOrder($order->id)[0];
            $this->assertSame($generatedContent['title'], $orderReminder['title']);
            $this->assertSame($generatedContent['notes'], $orderReminder['notes']);
        } finally {
            App::setLocale($previousLocale);
        }

        $this->actingAs($this->user)->get(route('reminders.index'))
            ->assertOk()
            ->assertSee('시술 후 1일차 후속 관리')
            ->assertSee('회복 상태를 확인합니다')
            ->assertDontSee('术后第 1 天跟进');
    }

    public function test_localization_migration_backfills_legacy_system_content(): void
    {
        $migration = require database_path('migrations/2026_08_07_000500_add_localized_content_to_reminders.php');
        $migration->down();
        $now = now();

        $templateId = DB::table('reminder_templates')->insertGetId([
            'name' => '术前确认',
            'title' => '术前准备确认',
            'suggestion' => '确认客户到店准备和术前注意事项',
            'default_trigger_type' => 'date_offset',
            'default_trigger_config' => json_encode(['field' => 'appointment_at', 'offset_days' => -3, 'time' => '09:00'], JSON_THROW_ON_ERROR),
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $duplicateTemplateId = DB::table('reminder_templates')->insertGetId([
            'name' => '术前确认',
            'title' => '术前准备确认',
            'suggestion' => '确认客户到店准备和术前注意事项',
            'default_trigger_type' => 'date_offset',
            'default_trigger_config' => json_encode(['field' => 'appointment_at', 'offset_days' => -3, 'time' => '09:00'], JSON_THROW_ON_ERROR),
            'is_system' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $appointmentReminderId = DB::table('reminders')->insertGetId([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->user->id,
            'source_type' => 'system',
            'reminder_type' => 'appointment',
            'title' => '术前 3 天确认',
            'suggestion' => '确认客户到店准备和术前注意事项',
            'due_at' => $now->addDay(),
            'dedupe_key' => hash('sha256', 'legacy-appointment'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $postTreatmentReminderId = DB::table('reminders')->insertGetId([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->user->id,
            'source_type' => 'system',
            'reminder_type' => 'post_treatment',
            'title' => '术后第 1 天跟进',
            'suggestion' => '问候恢复情况',
            'notes' => '项目：皮肤管理',
            'due_at' => $now->addDays(2),
            'dedupe_key' => hash('sha256', 'legacy-post-treatment'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration->up();

        $this->assertSame('pre_visit_confirmation', DB::table('reminder_templates')->where('id', $templateId)->value('system_key'));
        $this->assertNull(DB::table('reminder_templates')->where('id', $duplicateTemplateId)->value('system_key'));
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');
        try {
            $presenter = app(ReminderContentPresenter::class);
            $duplicateTemplate = ReminderTemplate::query()->findOrFail($duplicateTemplateId);
            $this->assertSame(
                $presenter->template(ReminderTemplate::query()->findOrFail($templateId))['name'],
                $presenter->template($duplicateTemplate)['name'],
            );
            $this->assertSame('시술 3일 전 확인', $presenter->reminder(Reminder::query()->findOrFail($appointmentReminderId))['title']);
            $postTreatmentReminder = Reminder::query()->findOrFail($postTreatmentReminderId);
            $postTreatmentContent = $presenter->reminder($postTreatmentReminder);
            $this->assertSame('시술 후 1일차 후속 관리', $postTreatmentContent['title']);
            $this->assertNotSame((string) $postTreatmentReminder->notes, $postTreatmentContent['notes']);
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_reminder_business_errors_follow_the_current_locale(): void
    {
        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            app(ReminderWorkspace::class)->createCustom(
                customerId: $this->customer->id,
                assignedTo: $this->user->id,
                title: '사용자 입력',
                dueAt: CarbonImmutable::now()->subMinute(),
                notes: null,
                suggestion: null,
                recurrence: null,
                templateId: null,
                actorId: $this->user->id,
            );
            $this->fail('Expected a localized reminder exception.');
        } catch (\DomainException $exception) {
            $this->assertSame('사용자 지정 알림 시각은 현재보다 이전일 수 없습니다.', $exception->getMessage());
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_creating_a_custom_reminder_dispatches_a_success_toast_before_navigation(): void
    {
        $this->actingAs($this->user);

        Livewire::test(ReminderCreate::class)
            ->set('customerId', (string) $this->customer->id)
            ->set('assignedTo', (string) $this->user->id)
            ->set('title', '创建后的提醒')
            ->set('dueAt', CarbonImmutable::now()->addDay()->format('Y-m-d\\TH:i'))
            ->call('save')
            ->assertDispatched('toast-show')
            ->assertRedirect(route('reminders.index'));

        $this->assertDatabaseHas('reminders', [
            'customer_id' => $this->customer->id,
            'title' => '创建后的提醒',
        ]);
    }

    public function test_shallow_rule_materializes_birthday_and_template_changes_do_not_rewrite_reminder(): void
    {
        $manager = app(ReminderRuleManager::class);
        $manager->saveRule(
            null,
            '生日提醒',
            'date_offset',
            ['field' => 'birth_date', 'offset_days' => 0, 'time' => '09:00'],
            'all_customers',
            [],
            '生日关怀',
            '联系客户表达生日祝福',
            2,
            $this->admin->id,
        );
        app(ReminderScheduler::class)->materialize();
        $reminder = Reminder::query()->where('title', '生日关怀')->firstOrFail();
        $this->assertSame('2026-08-02 09:00', $reminder->due_at->format('Y-m-d H:i'));

        $rule = ReminderRule::query()->firstOrFail();
        $manager->saveRule(
            $rule->id,
            '生日提醒',
            'date_offset',
            ['field' => 'birth_date', 'offset_days' => 0, 'time' => '10:00'],
            'all_customers',
            [],
            '新的生日标题',
            '新建议',
            2,
            $this->admin->id,
        );
        $this->assertSame('生日关怀', $reminder->refresh()->title);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'reminder-configuration', 'subject_id' => $rule->id]);
    }
}
