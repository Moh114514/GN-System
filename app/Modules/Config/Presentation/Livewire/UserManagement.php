<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Infrastructure\Time\BusinessClock;
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

    public ?int $editingBusinessGroupId = null;

    public ?int $selectedBusinessGroupId = null;

    public ?int $replacingBusinessGroupId = null;

    public string $replacementBdUserId = '';

    public string $replacementEffectiveFrom = '';

    public string $replacementReason = '';

    public ?int $deactivatingBusinessGroupId = null;

    public string $businessGroupDeactivateReason = '';

    public string $membershipGroupId = '';

    public string $membershipUserId = '';

    public string $membershipEffectiveFrom = '';

    public string $membershipEffectiveUntil = '';

    public string $membershipReason = '';

    public ?int $endingMembershipId = null;

    public string $membershipEndDate = '';

    public string $membershipEndReason = '';

    public string $assignmentAgentId = '';

    public string $assignmentGroupId = '';

    public string $assignmentEffectiveFrom = '';

    public string $assignmentEffectiveUntil = '';

    public string $assignmentReason = '';

    public ?int $endingAssignmentId = null;

    public string $assignmentEndDate = '';

    public string $assignmentEndReason = '';

    /** @var array<int, string> */
    public array $dingtalkMentionTypes = [];

    /** @var array<int, string> */
    public array $dingtalkMentionValues = [];

    public function mount(BusinessClock $clock): void
    {
        $businessDate = $clock->now()->toDateString();
        $this->membershipEffectiveFrom = $businessDate;
        $this->assignmentEffectiveFrom = $businessDate;
        $this->replacementEffectiveFrom = $businessDate;
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

    public function editBusinessGroup(int $id, ConfigurationUserCoordinator $users): void
    {
        $group = collect($users->businessGroups())->firstWhere('id', $id);
        if ($group === null) {
            return;
        }
        $this->editingBusinessGroupId = $id;
        $this->businessGroupCode = (string) $group['code'];
        $this->businessGroupName = (string) $group['name'];
    }

    public function saveBusinessGroup(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'businessGroupName' => ['required', 'string', 'max:255'],
            'businessGroupCode' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{1,31}$/'],
        ]);
        $this->run(function () use ($users): void {
            if ($this->editingBusinessGroupId === null) {
                $users->createBusinessGroup($this->businessGroupCode, $this->businessGroupName, (int) Auth::id(), request()->ip());
            } else {
                $users->updateBusinessGroupName($this->editingBusinessGroupId, $this->businessGroupName, (int) Auth::id(), request()->ip());
            }
            $this->cancelBusinessGroupEdit();
        }, $this->editingBusinessGroupId === null
            ? __('config.user_management.toast.business_group_created')
            : __('config.user_management.toast.business_group_updated'));
    }

    public function cancelBusinessGroupEdit(): void
    {
        $this->editingBusinessGroupId = null;
        $this->businessGroupCode = '';
        $this->businessGroupName = '';
    }

    public function viewBusinessGroup(int $id): void
    {
        $this->selectedBusinessGroupId = $id;
    }

    public function closeBusinessGroupDetail(): void
    {
        $this->selectedBusinessGroupId = null;
    }

    public function beginReplaceBusinessGroupBd(int $id): void
    {
        $this->replacingBusinessGroupId = $id;
        $this->replacementBdUserId = '';
        $this->replacementEffectiveFrom = app(BusinessClock::class)->now()->toDateString();
        $this->replacementReason = '';
    }

    public function cancelReplaceBusinessGroupBd(): void
    {
        $this->replacingBusinessGroupId = null;
        $this->replacementBdUserId = '';
        $this->replacementReason = '';
    }

    public function replaceBusinessGroupBd(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'replacingBusinessGroupId' => ['required', 'integer', 'min:1'],
            'replacementBdUserId' => ['required', 'integer', 'min:1'],
            'replacementEffectiveFrom' => ['required', 'date_format:Y-m-d'],
            'replacementReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->replaceBusinessGroupBd(
                (int) $this->replacingBusinessGroupId,
                (int) $this->replacementBdUserId,
                $this->replacementEffectiveFrom,
                $this->replacementReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->cancelReplaceBusinessGroupBd();
        }, __('config.user_management.toast.business_group_bd_replaced'));
    }

    public function beginBusinessGroupDeactivation(int $id): void
    {
        $this->deactivatingBusinessGroupId = $id;
        $this->businessGroupDeactivateReason = '';
    }

    public function cancelBusinessGroupDeactivation(): void
    {
        $this->deactivatingBusinessGroupId = null;
        $this->businessGroupDeactivateReason = '';
    }

    public function deactivateBusinessGroup(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'deactivatingBusinessGroupId' => ['required', 'integer', 'min:1'],
            'businessGroupDeactivateReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->deactivateBusinessGroup(
                (int) $this->deactivatingBusinessGroupId,
                $this->businessGroupDeactivateReason,
                (int) Auth::id(),
                request()->ip(),
            );
            if ($this->selectedBusinessGroupId === $this->deactivatingBusinessGroupId) {
                $this->selectedBusinessGroupId = null;
            }
            $this->cancelBusinessGroupDeactivation();
        }, __('config.user_management.toast.business_group_deactivated'));
    }

    public function assignMember(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'membershipGroupId' => ['required', 'integer', 'min:1'],
            'membershipUserId' => ['required', 'integer', 'min:1'],
            'membershipEffectiveFrom' => ['required', 'date_format:Y-m-d'],
            'membershipEffectiveUntil' => ['nullable', 'date_format:Y-m-d'],
            'membershipReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->assignBusinessGroupMember(
                (int) $this->membershipGroupId,
                (int) $this->membershipUserId,
                $this->membershipEffectiveFrom,
                $this->membershipEffectiveUntil === '' ? null : $this->membershipEffectiveUntil,
                $this->membershipReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->reset('membershipUserId', 'membershipEffectiveUntil', 'membershipReason');
        }, __('config.user_management.toast.business_group_member_assigned'));
    }

    public function beginMembershipEnd(int $membershipId): void
    {
        $this->endingMembershipId = $membershipId;
        $this->membershipEndDate = '';
        $this->membershipEndReason = '';
    }

    public function cancelMembershipEnd(): void
    {
        $this->endingMembershipId = null;
        $this->membershipEndDate = '';
        $this->membershipEndReason = '';
    }

    public function endMembership(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'endingMembershipId' => ['required', 'integer', 'min:1'],
            'membershipEndDate' => ['required', 'date_format:Y-m-d'],
            'membershipEndReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->endBusinessGroupMembership(
                (int) $this->endingMembershipId,
                $this->membershipEndDate,
                $this->membershipEndReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->cancelMembershipEnd();
        }, __('config.user_management.toast.business_group_member_ended'));
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

    public function beginAssignmentEnd(int $assignmentId): void
    {
        $this->endingAssignmentId = $assignmentId;
        $this->assignmentEndDate = '';
        $this->assignmentEndReason = '';
    }

    public function cancelAssignmentEnd(): void
    {
        $this->endingAssignmentId = null;
        $this->assignmentEndDate = '';
        $this->assignmentEndReason = '';
    }

    public function endAssignment(ConfigurationUserCoordinator $users): void
    {
        $this->validate([
            'endingAssignmentId' => ['required', 'integer', 'min:1'],
            'assignmentEndDate' => ['required', 'date_format:Y-m-d'],
            'assignmentEndReason' => ['required', 'string', 'max:2000'],
        ]);
        $this->run(function () use ($users): void {
            $users->endAgentBusinessGroupAssignment(
                (int) $this->endingAssignmentId,
                $this->assignmentEndDate,
                $this->assignmentEndReason,
                (int) Auth::id(),
                request()->ip(),
            );
            $this->cancelAssignmentEnd();
        }, __('config.user_management.toast.agent_assignment_ended'));
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
            'businessGroupSummaries' => $users->businessGroupSummaries(),
            'businessGroupDetails' => $this->selectedBusinessGroupId === null ? null : $users->businessGroupDetails($this->selectedBusinessGroupId),
            'memberships' => $users->memberships(),
            'unassignedUsers' => $users->unassignedUsers(),
            'memberCandidates' => $users->memberCandidates(),
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
