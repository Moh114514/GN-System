<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Audit\Presentation\Livewire\AuditLogIndex;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_route_requires_a_super_admin_with_confirmed_two_factor_authentication(): void
    {
        $user = User::factory()->create();
        $adminWithoutTwoFactor = User::factory()->superAdmin()->create();
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)->get(route('audit-logs.index'))->assertForbidden();
        $this->actingAs($adminWithoutTwoFactor)->get(route('audit-logs.index'))->assertRedirect(route('security.edit'));
        $this->actingAs($admin)->get(route('audit-logs.index'))
            ->assertOk()
            ->assertSee('全局审计日志')
            ->assertSee('返回用户管理')
            ->assertSee('日期')
            ->assertSee('操作者')
            ->assertSee('目标用户')
            ->assertSee('模块')
            ->assertSee('动作');
    }

    public function test_audit_log_filters_entries_and_only_displays_safe_masked_properties(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create(['name' => '审计管理员']);
        $target = User::factory()->create(['name' => '目标用户']);

        $this->activity($admin, $target, CarbonImmutable::parse('2026-08-03 09:00:00', 'Asia/Shanghai'), [
            'user_id' => $target->id,
            'role' => 'internal',
            'ip_address' => '203.0.113.45',
            'password' => 'secret-password',
            'email' => 'private@example.test',
            'before' => ['id' => $target->id, 'name' => '不应显示'],
        ]);
        $this->activity($admin, $target, CarbonImmutable::parse('2026-08-02 09:00:00', 'Asia/Shanghai'), [
            'user_id' => $target->id,
        ], description: '旧记录');

        /** @var Testable<AuditLogIndex> $component */
        $component = Livewire::actingAs($admin)
            // @phpstan-ignore argument.templateType
            ->test(AuditLogIndex::class);
        $component
            ->set('occurredOn', '2026-08-03')
            ->set('targetUserId', (string) $target->id)
            ->assertSee('203.0.113.***')
            ->assertSee('目标用户')
            ->assertDontSee('secret-password')
            ->assertDontSee('private@example.test')
            ->assertDontSee('不应显示')
            ->assertDontSee('旧记录');
    }

    public function test_audit_log_renders_known_message_keys_in_the_current_locale(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create();
        $this->activity(
            $admin,
            $target,
            now()->toImmutable(),
            ['message_key' => 'settlements.audit.approved', 'message_parameters' => []],
            '月结已审核通过',
        );

        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            /** @var Testable<AuditLogIndex> $component */
            $component = Livewire::actingAs($admin)
                // @phpstan-ignore argument.templateType
                ->test(AuditLogIndex::class);

            $component->assertSee(__('settlements.audit.approved'))
                ->assertDontSee('月结已审核通过')
                ->assertDontSee('message_key');
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_audit_log_localizes_known_historical_descriptions_without_message_keys(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create();
        $this->activity($admin, $target, now()->toImmutable(), [], '创建客户档案');

        $previousLocale = App::getLocale();
        App::setLocale('ko_KR');

        try {
            /** @var Testable<AuditLogIndex> $component */
            $component = Livewire::actingAs($admin)
                // @phpstan-ignore argument.templateType
                ->test(AuditLogIndex::class);

            $component->assertSee(__('audit.messages.customer_created'))
                ->assertDontSee('创建客户档案')
                ->assertDontSee(__('audit.legacy_original'));
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function test_unknown_historical_audit_descriptions_are_preserved_and_labeled(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create();
        $this->activity($admin, $target, now()->toImmutable(), [], '无法识别的历史自由文本');

        /** @var Testable<AuditLogIndex> $component */
        $component = Livewire::actingAs($admin)
            // @phpstan-ignore argument.templateType
            ->test(AuditLogIndex::class);

        $component->assertSee('无法识别的历史自由文本')
            ->assertSee(__('audit.legacy_original'));
    }

    public function test_user_management_exposes_the_audit_entry_and_topbar_keeps_localized_date_and_reminder_controls(): void
    {
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($admin)->get(route('configuration.users'))
            ->assertOk()
            ->assertSee('查看全局审计日志')
            ->assertSee('href="'.route('audit-logs.index').'"', false)
            ->assertSee('data-test="topbar-date-control"', false)
            ->assertSee('crm-localized-date-picker', false)
            ->assertSee('name="date"', false)
            ->assertDontSee('type="date"', false)
            ->assertSee('data-test="reminder-notification-button"', false)
            ->assertDontSee('calendar-days', false);
    }

    /** @param array<string, mixed> $properties */
    private function activity(User $causer, User $target, CarbonImmutable $occurredAt, array $properties, string $description = '用户资料已更新'): void
    {
        Activity::query()->create([
            'log_name' => 'auth',
            'description' => $description,
            'event' => 'updated',
            'causer_type' => $causer->getMorphClass(),
            'causer_id' => $causer->id,
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->id,
            'properties' => $properties,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
