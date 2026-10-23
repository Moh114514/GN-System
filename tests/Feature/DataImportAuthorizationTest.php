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
            ->assertSee('上传文件并预览')
            ->assertSee('机构代码（必填）')
            ->assertSee('机构正式名称（必填）')
            ->assertSee('历史名称 / 别名（选填）')
            ->assertSee('相同代码会更新该机构，新代码会创建机构')
            ->assertSee('多个别名用中文逗号、英文逗号或换行分隔')
            ->assertSee('导入时，dod 和 DOD皮肤科都会对应到同一家机构')
            ->assertSee('选择对应导入批次并点击“重新检查”')
            ->assertSee('保存会立即更新系统机构及别名');
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
            ->assertSee('기관 코드 (필수)')
            ->assertSee('기관 공식 명칭 (필수)')
            ->assertSee('과거 명칭 / 별칭 (선택)')
            ->assertSee('같은 코드는 해당 기관을 수정하고 새 코드는 기관을 생성합니다')
            ->assertSee('전체 최대 1000자')
            ->assertSee('dod와 DOD피부과는 같은 기관으로 연결됩니다')
            ->assertSee('해당 가져오기 배치를 선택하고 “다시 검사”')
            ->assertSee('저장하면 시스템 기관과 별칭이 즉시 변경')
            ->assertDontSee('历史数据导入');
    }
}
