<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Modules\Config\Application\Services\ConfigurationUserCoordinator;
use DomainException;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
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
                ? __('config.user_management.toast.invited')
                : __('config.user_management.toast.invitation_failed'),
        );
    }

    public function resend(int $id, ConfigurationUserCoordinator $users): void
    {
        $this->run(function () use ($id, $users): void {
            $status = $users->resend($id, (int) Auth::id(), request()->ip());
            Flux::toast(
                variant: $status === 'sent' ? 'success' : 'danger',
                text: $status === 'sent'
                    ? __('config.user_management.toast.resent')
                    : __('config.user_management.toast.resend_failed'),
            );
        });
    }

    public function resetPassword(int $id, ConfigurationUserCoordinator $users): void
    {
        $this->run(function () use ($id, $users): void {
            $status = $users->sendPasswordResetLink($id, (int) Auth::id(), request()->ip());
            Flux::toast(
                variant: $status === 'sent' ? 'success' : 'danger',
                text: $status === 'sent'
                    ? __('config.user_management.toast.password_reset_sent')
                    : __('config.user_management.toast.password_reset_failed'),
            );
        });
    }

    public function toggleRole(int $id, bool $makeSuperAdmin, ConfigurationUserCoordinator $users): void
    {
        $this->run(fn () => $users->changeRole($id, $makeSuperAdmin, (int) Auth::id(), request()->ip()), __('config.user_management.toast.role_updated'));
    }

    public function toggleActive(int $id, bool $activate, ConfigurationUserCoordinator $users): void
    {
        $this->run(
            fn () => $users->setActive($id, $activate, (int) Auth::id(), request()->ip()),
            $activate ? __('config.user_management.toast.account_activated') : __('config.user_management.toast.account_deactivated'),
        );
    }

    public function render(ConfigurationUserCoordinator $users): View
    {
        return view('livewire.configuration.user-management', ['users' => $users->users()])
            ->title(__('config.user_management.title'));
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
