<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SuperAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'super-admin'])
            ->get('/_test/super-admin', fn () => response('ok'));
    }

    public function test_normal_users_are_forbidden_from_super_admin_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_test/super-admin')
            ->assertForbidden();
    }

    public function test_super_admins_can_access_super_admin_routes(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/_test/super-admin')
            ->assertOk();
    }
}
