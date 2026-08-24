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
    public bool $embedded = false;

    public string $name = '';

    public string $email = '';

    public string $inviteRole = 'customer_service';

    /** @var array<int, string> */
    public array $roleSelections = [];

    public string $businessGroupCode = '';

    public string $businessGroupName = '';

    public string $membershipGroupId = '';

    public string $membershipUserId = '';

    public string $membershipRole = 'customer_service';

    public string $membershipEffectiveFrom = '';

    public string $membershipEffectiveUntil = '';

    public string $membershipReason = '';

    public string $assignmentAgentId = '';

    public string $assignmentGroupId = '';

    public string $assignmentEffectiveFrom = '';

    public string $assignmentEffectiveUntil = '';

    public string $assignmentReason = '';

    /** @var array<int, string> */
    public array $dingtalkMentionTypes = [];

    /** @var array<int, string> */
    public array $dingtalkMentionValues = [];

    public function mount(): void
    {
        $this->membershipEffectiveFrom = now()->toDateString();
        $this->assignmentEffectiveFrom = now()->toDateString();
    }

    public function invite(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'inviteRole' => ['required', 'string', 'in:super_admin,bd_manager,customer_service'],
        ]);
        $result = $users->inviteWithRole($this->name, $this->email, $this->inviteRole, (int) Auth::id(), request()->ip());
        $this->reset('name', 'email', 'inviteRole');
        $this->inviteRole = 'customer_service';
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

    public function saveRole(int $id, ConfigurationUserCoordinator $users): void
    {
        $role = (string) ($this->roleSelections[$id] ?? '');
        $this->validate([
            "roleSelections.{$id}" => ['required', 'string', 'in:super_admin,bd_manager,customer_service'],
        ]);
        $this->run(
            fn () => $users->setRole($id, $role, (int) Auth::id(), request()->ip()),
            __('config.user_management.toast.role_updated'),
        );
    }

    public function toggleActive(int $id, bool $activate, ConfigurationUserCoordinator $users): void
    {
        $this->run(
            fn () => $users->setActive($id, $activate, (int) Auth::id(), request()->ip()),
            $activate ? __('config.user_management.toast.account_activated') : __('config.user_management.toast.account_deactivated'),
        );
    }

    public function saveDingTalkMention(int $id, ConfigurationUserCoordinator $users): void
    {
        $type = trim((string) ($this->dingtalkMentionTypes[$id] ?? ''));
        $value = trim((string) ($this->dingtalkMentionValues[$id] ?? ''));
        $this->dingtalkMentionTypes[$id] = $type;
        $this->dingtalkMentionValues[$id] = $value;
        $this->validate([
            "dingtalkMentionTypes.{$id}" => ['nullable', 'string', 'in:user_id,mobile'],
            "dingtalkMentionValues.{$id}" => ['nullable', 'string', 'max:255'],
        ]);
        $this->run(
            fn () => $users->setDingTalkMention($id, $type === '' ? null : $type, $value === '' ? null : $value, (int) Auth::id(), request()->ip()),
            __('config.user_management.toast.dingtalk_updated'),
        );
    }

    public function createBusinessGroup(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'businessGroupCode' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,31}$/'],
            'businessGroupName' => ['required', 'string', 'max:255'],
        ]);
        $this->run(function () use ($users): void {
            $users->createBusinessGroup($this->businessGroupCode, $this->businessGroupName, (int) Auth::id(), request()->ip());
            $this->reset('businessGroupCode', 'businessGroupName');
        }, __('config.user_management.toast.business_group_created'));
    }

    public function assignMember(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'membershipGroupId' => ['required', 'integer', 'min:1'],
            'membershipUserId' => ['required', 'integer', 'min:1'],
            'membershipRole' => ['required', 'string', 'in:bd_manager,customer_service'],
            'membershipEffectiveFrom' => ['required', 'date_format:Y-m-d'],
            'membershipEffectiveUntil' => ['nullable', 'date_format:Y-m-d'],
            'membershipReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->assignBusinessGroupMember(
                (int) $this->membershipGroupId,
                (int) $this->membershipUserId,
                $this->membershipRole,
                $this->membershipEffectiveFrom,
                $this->membershipEffectiveUntil === '' ? null : $this->membershipEffectiveUntil,
                $this->membershipReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->reset('membershipUserId', 'membershipEffectiveUntil', 'membershipReason');
        }, __('config.user_management.toast.business_group_member_assigned'));
    }

    public function assignAgent(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'assignmentAgentId' => ['required', 'integer', 'min:1'],
            'assignmentGroupId' => ['required', 'integer', 'min:1'],
            'assignmentEffectiveFrom' => ['required', 'date_format:Y-m-d'],
            'assignmentEffectiveUntil' => ['nullable', 'date_format:Y-m-d'],
            'assignmentReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->assignAgentToBusinessGroup(
                (int) $this->assignmentAgentId,
                (int) $this->assignmentGroupId,
                $this->assignmentEffectiveFrom,
                $this->assignmentEffectiveUntil === '' ? null : $this->assignmentEffectiveUntil,
                $this->assignmentReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->reset('assignmentAgentId', 'assignmentEffectiveUntil', 'assignmentReason');
        }, __('config.user_management.toast.agent_assignment_created'));
    }

    public function render(ConfigurationUserCoordinator $users): View
    {
        $records = $users->users();
        foreach ($records as $record) {
            $id = (int) $record['id'];
            $this->roleSelections[$id] ??= (string) $record['role'];
            $this->dingtalkMentionTypes[$id] ??= (string) ($record['dingtalk_mention_type'] ?? '');
            $this->dingtalkMentionValues[$id] ??= (string) ($record['dingtalk_mention_value'] ?? '');
        }

        return view('livewire.configuration.user-management', [
            'users' => $records,
            'businessGroups' => $users->businessGroups(),
            'memberships' => $users->memberships(),
            'unassignedUsers' => $users->unassignedUsers(),
            'agents' => $users->agents(),
            'agentAssignments' => $users->agentAssignments(),
            'unassignedAgents' => $users->unassignedAgents(),
        ])
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
