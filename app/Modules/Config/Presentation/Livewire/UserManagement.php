<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Modules\Config\Application\Services\ConfigurationUserCoordinator;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('内部用户管理')]
class UserManagement extends Component
{
    public string $name = '';

    public string $email = '';

    public bool $isSuperAdmin = false;

    public function invite(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'isSuperAdmin' => ['boolean'],
        ]);
        $result = $users->invite($this->name, $this->email, $this->isSuperAdmin, (int) Auth::id(), request()->ip());
        $this->reset('name', 'email', 'isSuperAdmin');
        Flux::toast(
            variant: $result['invitation_status'] === 'sent' ? 'success' : 'danger',
            text: $result['invitation_status'] === 'sent'
                ? '用户已创建，一次性密码设置链接已发送。'
                : '用户已创建，但邀请邮件发送失败；请检查邮件配置后重发。',
        );
    }

    public function resend(int $id, ConfigurationUserCoordinator $users): void
    {
        $this->run(function () use ($id, $users): void {
            $status = $users->resend($id, (int) Auth::id(), request()->ip());
            Flux::toast(
                variant: $status === 'sent' ? 'success' : 'danger',
                text: $status === 'sent' ? '邀请邮件已重发。' : '邀请邮件仍发送失败，请检查 SMTP 配置。',
            );
        });
    }

    public function toggleRole(int $id, bool $makeSuperAdmin, ConfigurationUserCoordinator $users): void
    {
        $this->run(fn () => $users->changeRole($id, $makeSuperAdmin, (int) Auth::id(), request()->ip()), '用户角色已更新。');
    }

    public function toggleActive(int $id, bool $activate, ConfigurationUserCoordinator $users): void
    {
        $this->run(fn () => $users->setActive($id, $activate, (int) Auth::id(), request()->ip()), $activate ? '账号已启用。' : '账号已停用，现有会话已清理。');
    }

    public function render(ConfigurationUserCoordinator $users): View
    {
        return view('livewire.configuration.user-management', ['users' => $users->users()]);
    }

    private function run(\Closure $operation, ?string $success = null): void
    {
        try {
            $operation();
            if ($success !== null) {
                Flux::toast(variant: 'success', text: $success);
            }
        } catch (DomainException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }
}
