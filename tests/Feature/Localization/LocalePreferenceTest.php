<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_store_locale_in_session_and_cookie(): void
    {
        $response = $this->from(route('login'))->post(route('locale.update'), [
            'locale' => 'ko_KR',
        ]);

        $response
            ->assertRedirect(route('login'))
            ->assertCookie('locale');

        $this->assertSame('ko_KR', session('locale'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('다시 오신 것을 환영합니다');
    }

    public function test_login_page_exposes_language_switcher(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('action="'.route('locale.update').'"', false)
            ->assertSee(__('language.options.ko_KR', [], 'ko_KR'));
    }

    public function test_authenticated_locale_is_saved_to_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('locale.update'), ['locale' => 'ko_KR'])
            ->assertRedirect(route('profile.edit'));

        $this->assertSame('ko_KR', $user->refresh()->preferred_locale);

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('프로필 설정');
    }

    public function test_authenticated_user_can_open_the_language_settings_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('language.edit'))
            ->assertOk()
            ->assertSee('语言设置')
            ->assertSee('한국어')
            ->assertSee('action="'.route('locale.update').'"', false)
            ->assertSee('返回总览');
    }

    public function test_authenticated_user_menu_exposes_language_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('href="'.route('language.edit').'"', false)
            ->assertSee(__('navigation.language'));
    }

    public function test_authenticated_topbar_exposes_language_switcher(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('data-test="topbar-language-button"', false)
            ->assertSee('data-test="topbar-language-option-zh_CN"', false)
            ->assertSee('data-test="topbar-language-option-ko_KR"', false)
            ->assertSee('action="'.route('locale.update').'"', false)
            ->assertSee(__('language.options.ko_KR', [], 'ko_KR'));
    }

    public function test_inactive_user_message_follows_preferred_locale(): void
    {
        $user = User::factory()->create([
            'is_active' => false,
            'preferred_locale' => 'ko_KR',
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.middleware.account_disabled', [], 'ko_KR')]);
    }

    public function test_super_admin_two_factor_message_follows_preferred_locale(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => true,
            'preferred_locale' => 'ko_KR',
            'two_factor_confirmed_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('status', __('auth.middleware.two_factor_required', [], 'ko_KR'));
    }

    public function test_stale_session_message_follows_preferred_locale(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ko_KR', 'session_version' => 2]);

        $this->actingAs($user)
            ->withSession(['auth.session_version' => 1])
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => __('auth.middleware.session_expired', [], 'ko_KR')]);
    }

    public function test_user_preference_has_priority_over_session_and_cookie(): void
    {
        $user = User::factory()->create(['preferred_locale' => 'ko_KR']);

        $cookieResponse = $this->from(route('login'))->post(route('locale.update'), [
            'locale' => 'zh_CN',
        ]);
        $cookie = $cookieResponse->getCookie('locale');

        $this->actingAs($user)
            ->withCookie('locale', $cookie->getValue())
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false);
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->post(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHasErrors('locale');

        $this->assertSame('zh_CN', $user->refresh()->preferred_locale);
    }

    public function test_default_locale_is_simplified_chinese(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<html lang="zh-CN"', false);
    }

    public function test_anonymous_locale_is_synced_to_user_on_login(): void
    {
        $user = User::factory()->create();

        $this->withSession(['locale' => 'ko_KR'])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertSame('ko_KR', $user->refresh()->preferred_locale);
    }
}
