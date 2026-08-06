<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('返回总览')
            ->assertSee('href="'.route('dashboard').'"', false);
    }

    public function test_appearance_page_has_parent_navigation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('返回总览')
            ->assertSee('href="'.route('dashboard').'"', false);
    }

    public function test_resolved_dark_appearance_is_rendered_on_the_document_root(): void
    {
        $this->actingAs(User::factory()->create());

        $locale = str_replace('_', '-', app()->getLocale());

        $this->withUnencryptedCookie('flux_resolved_appearance', 'dark')
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" class="dark">', false)
            ->assertSee('window.Flux.applyAppearance =', false)
            ->assertDontSee('MutationObserver', false)
            ->assertDontSee('livewire:navigating', false);
    }

    public function test_non_dark_appearance_cookie_does_not_render_the_dark_class(): void
    {
        $this->actingAs(User::factory()->create());

        $locale = str_replace('_', '-', app()->getLocale());

        $this->withUnencryptedCookie('flux_resolved_appearance', 'light')
            ->get(route('appearance.edit'))
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" class="">', false);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
