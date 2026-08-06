<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AdminMaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_admins_does_not_expose_credentials(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'email' => 'admin@example.com']);

        $this->artisan('app:list-admins')
            ->expectsTable(['ID', 'Name', 'Email', 'Active', 'Disabled at', 'Session version', '2FA'], [
                [$admin->id, $admin->name, $admin->email, 'yes', '-', $admin->session_version, 'no'],
            ])
            ->assertSuccessful();
    }

    public function test_disabling_admin_requires_reason_preserves_last_active_super_admin_and_audits(): void
    {
        $first = User::factory()->create(['is_super_admin' => true]);
        $second = User::factory()->create(['is_super_admin' => true]);
        DB::table('sessions')->insert([
            'id' => 'admin-session',
            'user_id' => $first->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->artisan('app:disable-admin', ['admin' => $first->id])
            ->expectsOutput('A non-empty --reason is required.')
            ->assertFailed();

        $this->artisan('app:disable-admin', ['admin' => $first->id, '--reason' => 'UAT access rotation', '--operator' => 'test-operator'])
            ->assertSuccessful();

        $first->refresh();
        $this->assertFalse($first->is_active);
        $this->assertSame(2, $first->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'admin-session']);
        $this->assertDatabaseHas('activity_log', ['event' => 'admin_disabled', 'subject_id' => $first->id]);

        $this->artisan('app:disable-admin', ['admin' => $second->id, '--reason' => 'last admin test', '--operator' => 'test-operator'])
            ->expectsOutput('The last active super administrator cannot be disabled.')
            ->assertFailed();
    }

    public function test_enable_and_password_reset_invalidate_sessions_and_can_clear_two_factor(): void
    {
        $admin = User::factory()->create([
            'is_super_admin' => true,
            'is_active' => false,
            'disabled_at' => now(),
            'two_factor_secret' => 'secret',
            'two_factor_recovery_codes' => 'codes',
            'two_factor_confirmed_at' => now(),
        ]);
        DB::table('sessions')->insert([
            'id' => 'password-session',
            'user_id' => $admin->id,
            'payload' => 'payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->artisan('app:enable-admin', ['admin' => $admin->id, '--reason' => 'restore access', '--operator' => 'test-operator'])
            ->assertSuccessful();
        $this->artisan('app:reset-admin-password', [
            'admin' => $admin->id,
            '--reason' => 'credential rotation',
            '--operator' => 'test-operator',
            '--clear-2fa' => true,
        ])
            ->expectsQuestion('New administrator password', 'StrongPass!123')
            ->expectsQuestion('Confirm administrator password', 'StrongPass!123')
            ->assertSuccessful();

        $admin->refresh();
        $this->assertTrue($admin->is_active);
        $this->assertSame(2, $admin->session_version);
        $this->assertTrue(Hash::check('StrongPass!123', $admin->password));
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'password-session']);
        $this->assertDatabaseHas('activity_log', ['event' => 'admin_password_reset', 'subject_id' => $admin->id]);
    }
}
