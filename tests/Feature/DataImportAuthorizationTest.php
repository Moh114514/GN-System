<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_normal_users_are_forbidden(): void
    {
        $this->get(route('data-imports.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('data-imports.index'))
            ->assertForbidden();
    }

    public function test_confirmed_super_admin_can_open_import_manager(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create();

        $this->actingAs($user)
            ->get(route('data-imports.index'))
            ->assertOk()
            ->assertSee('历史数据导入')
            ->assertSee('返回数据导入与迁移')
            ->assertSee('href="'.route('configuration.data-maintenance').'"', false)
            ->assertSee('上传文件并预览');
    }

    public function test_korean_super_admin_sees_localized_import_manager_copy(): void
    {
        $user = User::factory()->superAdmin()->withTwoFactor()->create(['preferred_locale' => 'ko_KR']);

        $this->actingAs($user)
            ->get(route('data-imports.index'))
            ->assertOk()
            ->assertSee('<html lang="ko-KR"', false)
            ->assertSee('기록 데이터 가져오기')
            ->assertSee('파일 업로드 및 미리보기')
            ->assertDontSee('历史数据导入');
    }
}
