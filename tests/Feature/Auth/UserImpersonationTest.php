<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Application\Contracts\AccessContextResolver;
use App\Modules\Auth\Domain\UserRole;
use App\Modules\Auth\Infrastructure\Models\BusinessGroup;
use App\Modules\Auth\Infrastructure\Models\BusinessGroupMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UserImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth'])
            ->get('/_test/impersonation-effective', function (Request $request) {
                return response()->json([
                    'auth_user_id' => Auth::id(),
                    'request_user_id' => $request->user()?->id,
                    'context' => app(AccessContextResolver::class)->current()->toSnapshot(),
                ]);
            });
    }

    public function test_local_enabled_super_admin_can_impersonate_a_business_user_without_mutating_users(): void
    {
        $this->enableImpersonation('local');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::BdManager]);
        $before = [$target->role, $target->is_super_admin];

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->get('/_test/impersonation-effective')
            ->assertOk()
            ->assertJsonPath('auth_user_id', $target->id)
            ->assertJsonPath('request_user_id', $target->id)
            ->assertJsonPath('context.user_id', $target->id)
            ->assertJsonPath('context.role', UserRole::BdManager->value);

        $target->refresh();
        $this->assertSame($before[0], $target->role);
        $this->assertSame($before[1], $target->is_super_admin);
        $this->assertAuthenticatedAs($target);
        $this->get('/')->assertSessionHas('auth.impersonation.owner_user_id', $admin->id);
        $this->get('/')->assertSessionHas('auth.impersonation.target_user_id', $target->id);
    }

    public function test_uat_enabled_can_impersonate_and_effective_access_context_matches_target_user(): void
    {
        $this->enableImpersonation('uat');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::CustomerService]);
        $group = BusinessGroup::query()->create([
            'code' => 'IMPERSONATION',
            'name' => '模拟业务组',
            'is_active' => true,
            'created_by' => $admin->id,
        ]);
        BusinessGroupMembership::query()->create([
            'business_group_id' => $group->id,
            'user_id' => $target->id,
            'member_role' => UserRole::CustomerService->value,
            'effective_from' => '2026-01-01',
            'effective_until' => null,
            'assigned_by' => $admin->id,
            'reason' => '模拟测试',
        ]);

        $expected = app(AccessContextResolver::class)->forUser($target);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id])
            ->assertRedirect();

        $actual = $this->getJson('/_test/impersonation-effective')->json('context');
        $this->assertSame($expected->toSnapshot(), $actual);
    }

    public function test_production_is_hard_disabled_even_when_the_flag_is_true(): void
    {
        config()->set('app.deployment_environment', 'production');
        config()->set('app.impersonation_enabled', true);
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::BdManager]);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id])
            ->assertForbidden();

        $this->get('/')->assertSessionMissing('auth.impersonation.owner_user_id');
        $this->get('/')->assertSessionMissing('auth.impersonation.target_user_id');
    }

    public function test_non_super_admin_cannot_start_or_stop_impersonation(): void
    {
        $this->enableImpersonation('testing');
        $user = User::factory()->create(['role' => UserRole::BdManager]);
        $target = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($user)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id])
            ->assertForbidden();

        $this->delete(route('test.impersonation.destroy'))->assertForbidden();
    }

    public function test_unconfirmed_super_admin_cannot_start_impersonation(): void
    {
        $this->enableImpersonation('testing');
        $admin = User::factory()->superAdmin()->create();
        $target = User::factory()->create(['role' => UserRole::BdManager]);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id])
            ->assertRedirect(route('security.edit', absolute: false));

        $this->get('/')->assertSessionMissing('auth.impersonation.owner_user_id');
    }

    public function test_disabled_or_super_admin_targets_cannot_be_impersonated(): void
    {
        $this->enableImpersonation('testing');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $disabled = User::factory()->create(['role' => UserRole::BdManager, 'is_active' => false]);
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $disabled->id])
            ->assertForbidden();
        $this->post(route('test.impersonation.store'), ['user_id' => $superAdmin->id])
            ->assertForbidden();
    }

    public function test_impersonated_business_users_lose_super_admin_routes_and_configuration_navigation(): void
    {
        $this->enableImpersonation('testing');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id]);

        $this->get(route('configuration.index'))->assertForbidden();
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee(__('navigation.test_identity'))
            ->assertSee(__('navigation.impersonation_warning', [
                'role' => __('config.user_management.roles.customer_service'),
                'name' => $target->name,
            ]))
            ->assertDontSee(__('navigation.configuration'));
    }

    public function test_stopping_impersonation_restores_the_owner_immediately(): void
    {
        $this->enableImpersonation('testing');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::BdManager]);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id]);
        $this->delete(route('test.impersonation.destroy'))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->get('/_test/impersonation-effective')
            ->assertOk()
            ->assertJsonPath('auth_user_id', $admin->id)
            ->assertJsonPath('request_user_id', $admin->id)
            ->assertJsonPath('context.user_id', $admin->id)
            ->assertJsonPath('context.unrestricted', true);
        $this->get('/')->assertSessionMissing('auth.impersonation.owner_user_id');
        $this->get('/')->assertSessionMissing('auth.impersonation.target_user_id');
    }

    public function test_logout_clears_impersonation_state(): void
    {
        $this->enableImpersonation('testing');
        $admin = User::factory()->superAdmin()->withTwoFactor()->create();
        $target = User::factory()->create(['role' => UserRole::CustomerService]);

        $this->actingAs($admin)
            ->post(route('test.impersonation.store'), ['user_id' => $target->id]);
        $this->post(route('logout'))->assertRedirect();

        $this->assertGuest();
        $this->get('/')->assertSessionMissing('auth.impersonation.owner_user_id');
        $this->get('/')->assertSessionMissing('auth.impersonation.target_user_id');
    }

    private function enableImpersonation(string $deploymentEnvironment): void
    {
        config()->set('app.deployment_environment', $deploymentEnvironment);
        config()->set('app.impersonation_enabled', true);
    }
}
