<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Infrastructure\Notifications\InternalUserInvitationNotification;
use App\Modules\Auth\Infrastructure\Notifications\UserPasswordResetNotification;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, UserPasswordResetNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, UserPasswordResetNotification::class, function ($notification) use ($user) {
            $response = $this->get(route('account.password-reset', [
                'token' => $notification->token,
                'email' => $user->email,
            ]));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, UserPasswordResetNotification::class, function ($notification) use ($user) {
            $response = $this->post(route('account.password-reset.store', $notification->token), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertOk()
                ->assertSee($user->email);

            $this->assertTrue(Hash::check('password', $user->refresh()->password));

            return true;
        });
    }

    public function test_legacy_fortify_reset_link_can_still_complete_a_pending_invitation(): void
    {
        $user = User::factory()->create([
            'invitation_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $token = Password::broker()->createToken($user);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login', absolute: false));

        $user->refresh();
        $this->assertSame('accepted', $user->invitation_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_forgot_password_for_an_unaccepted_user_keeps_the_account_on_the_invitation_flow(): void
    {
        Notification::fake();
        $user = User::factory()->create(['invitation_status' => 'pending']);

        $this->post(route('password.request'), ['email' => $user->email])->assertSessionHasNoErrors();

        Notification::assertSentTo($user, InternalUserInvitationNotification::class, function ($notification) use ($user): bool {
            $this->assertStringContainsString('/account/invitation/', $notification->toMail($user)->actionUrl);

            return true;
        });
    }

    public function test_logged_in_admin_can_complete_an_invitation_without_switching_sessions(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $invited = User::factory()->create([
            'email' => 'invite@example.com',
            'invitation_status' => 'pending',
            'email_verified_at' => null,
        ]);
        $token = Password::broker()->createToken($invited);

        $this->actingAs($admin)
            ->get(route('account.invitation', ['token' => $token, 'email' => $invited->email]))
            ->assertOk()
            ->assertSee('account/invitation/'.$token, false);

        $this->actingAs($admin)->post(route('account.invitation.store', $token), [
            'email' => $invited->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertOk()->assertSee($invited->email);

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('accepted', $invited->refresh()->invitation_status);
        $this->assertNotNull($invited->email_verified_at);
    }

    public function test_password_reset_does_not_accept_an_invitation_or_change_account_metadata(): void
    {
        $user = User::factory()->create([
            'invitation_status' => 'accepted',
            'is_super_admin' => true,
            'is_active' => false,
            'session_version' => 4,
        ]);
        $token = Password::broker()->createToken($user);
        DB::table('sessions')->insert([
            'id' => 'user-reset-session',
            'user_id' => $user->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->post(route('account.password-reset.store', $token), [
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertOk()->assertSee($user->email);

        $user->refresh();
        $this->assertSame('accepted', $user->invitation_status);
        $this->assertTrue($user->is_super_admin);
        $this->assertFalse($user->is_active);
        $this->assertSame(5, $user->session_version);
        $this->assertNull($user->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'user-reset-session']);
    }

    public function test_invitation_notification_has_a_distinct_url_and_locale_preference(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ko_KR']);
        $notification = new InternalUserInvitationNotification('invitation-token');

        $this->assertInstanceOf(HasLocalePreference::class, $user);
        $this->assertSame('ko_KR', $user->preferredLocale());
        App::setLocale('ko_KR');
        $mail = $notification->toMail($user);

        $this->assertStringContainsString('/account/invitation/', (string) $mail->actionUrl);
        $this->assertSame(__('auth.mail.invitation.subject'), $mail->subject);
    }

    public function test_invalid_or_reused_invitation_tokens_cannot_be_used(): void
    {
        $user = User::factory()->create(['invitation_status' => 'pending']);

        $this->get(route('account.invitation', ['token' => 'invalid', 'email' => $user->email]))
            ->assertNotFound();

        $token = Password::broker()->createToken($user);
        $this->post(route('account.invitation.store', $token), [
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertOk();

        $this->get(route('account.invitation', ['token' => $token, 'email' => $user->email]))
            ->assertNotFound();
    }

    public function test_expired_invitation_token_cannot_be_used(): void
    {
        $user = User::factory()->create(['invitation_status' => 'pending']);
        $token = Password::broker()->createToken($user);

        $this->travel(61)->minutes();

        $this->get(route('account.invitation', ['token' => $token, 'email' => $user->email]))
            ->assertNotFound();

        $this->post(route('account.invitation.store', $token), [
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertNotFound();

        $this->travelBack();
    }
}
