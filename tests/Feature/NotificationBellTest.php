<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Config\Infrastructure\Models\InternalNotification;
use App\Modules\Config\Presentation\Livewire\NotificationBell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_bell_shows_only_current_users_unread_reminders_and_marks_opened_notification_read(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = InternalNotification::query()->create([
            'user_id' => $user->id,
            'event_type' => 'reminder',
            'event_key' => 'reminder:4321',
            'title' => '客户回访提醒',
            'body' => '请联系客户并记录结果。',
            'link' => route('reminders.index'),
        ]);
        InternalNotification::query()->create([
            'user_id' => $otherUser->id,
            'event_type' => 'reminder',
            'event_key' => 'reminder:9876',
            'title' => '其他员工通知',
            'body' => '不应展示给当前用户。',
            'link' => route('reminders.index'),
        ]);
        $this->actingAs($user);

        Livewire::test(NotificationBell::class)
            ->assertSee('data-test="notification-unread-count"', false)
            ->assertSee('1')
            ->call('toggle')
            ->assertSee('客户回访提醒')
            ->assertDontSee('其他员工通知')
            ->call('openNotification', $notification->id)
            ->assertRedirect(route('reminders.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }
}
