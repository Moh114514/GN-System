<div>
    @unless ($embedded)
        <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
    @endunless
    @unless ($embedded)
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.user_management.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.user_management.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.user_management.description') }}</p>
        </div>
        <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300" wire:navigate>
            {{ __('config.user_management.audit_link') }}
            <flux:icon.arrow-right class="size-4" aria-hidden="true" />
        </a>
    </section>
    @endunless

    <form wire:submit="invite" class="w-full max-w-[720px] rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.invite_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.user_management.invite_description') }}</p>
        <div class="mt-5 grid gap-4 sm:grid-cols-[1fr_1.5fr]">
            <flux:input wire:model="name" :label="__('config.user_management.name')" class="min-w-0" />
            <flux:input wire:model="email" type="email" :label="__('config.user_management.email')" class="min-w-0" />
            <flux:select wire:model="inviteRole" :label="__('config.user_management.role')">
                <flux:select.option value="super_admin">{{ __('config.user_management.roles.super_admin') }}</flux:select.option>
                <flux:select.option value="bd_manager">{{ __('config.user_management.roles.bd_manager') }}</flux:select.option>
                <flux:select.option value="customer_service">{{ __('config.user_management.roles.customer_service') }}</flux:select.option>
            </flux:select>
        </div>
        <div class="mt-5 flex justify-end">
            <flux:button type="submit" variant="primary" class="w-auto shrink-0">{{ __('config.user_management.create_invitation') }}</flux:button>
        </div>
    </form>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.list_heading') }}</h3>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table">
                <thead><tr><th>{{ __('config.user_management.table.user') }}</th><th>{{ __('config.user_management.dingtalk_mention') }}</th><th>{{ __('config.user_management.table.role') }}</th><th>{{ __('config.user_management.table.account') }}</th><th>{{ __('config.user_management.table.invitation') }}</th><th>{{ __('config.user_management.table.actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td><strong>{{ $user['name'] }}</strong><br><span class="text-xs text-zinc-500">{{ $user['email'] }}</span></td>
                            <td>
                                <div class="flex items-end gap-2">
                                    <flux:select wire:model="dingtalkMentionTypes.{{ $user['id'] }}" :label="__('config.user_management.dingtalk_mention_type')" size="sm">
                                        <flux:select.option value="">{{ __('config.user_management.dingtalk_mention_empty') }}</flux:select.option>
                                        <flux:select.option value="user_id">{{ __('config.user_management.dingtalk_mention_types.user_id') }}</flux:select.option>
                                        <flux:select.option value="mobile">{{ __('config.user_management.dingtalk_mention_types.mobile') }}</flux:select.option>
                                    </flux:select>
                                    <flux:input wire:model="dingtalkMentionValues.{{ $user['id'] }}" :label="__('config.user_management.dingtalk_mention_value')" size="sm" />
                                    <flux:button wire:click="saveDingTalkMention({{ $user['id'] }})" variant="ghost" size="sm">{{ __('config.user_management.actions.save_dingtalk_mention') }}</flux:button>
                                </div>
                            </td>
                            <td>
                                <div class="flex items-end gap-2">
                                    <flux:select wire:model="roleSelections.{{ $user['id'] }}" :label="__('config.user_management.role')" size="sm">
                                        <flux:select.option value="super_admin">{{ __('config.user_management.roles.super_admin') }}</flux:select.option>
                                        <flux:select.option value="bd_manager">{{ __('config.user_management.roles.bd_manager') }}</flux:select.option>
                                        <flux:select.option value="customer_service">{{ __('config.user_management.roles.customer_service') }}</flux:select.option>
                                    </flux:select>
                                    <flux:button wire:click="saveRole({{ $user['id'] }})" variant="ghost" size="sm">{{ __('config.user_management.actions.save_role') }}</flux:button>
                                </div>
                            </td>
                            <td>{{ $user['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                            <td>
                                {{ __('config.invitation_status.'.$user['invitation_status']) }}
                                @if ($user['invitation_sent_at'])<br><span class="text-xs text-zinc-500">{{ $user['invitation_sent_at'] }}</span>@endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button wire:click="toggleActive({{ $user['id'] }}, {{ $user['is_active'] ? 'false' : 'true' }})" variant="ghost" size="sm">
                                        {{ $user['is_active'] ? __('config.user_management.actions.disable') : __('config.user_management.actions.enable') }}
                                    </flux:button>
                                    @if (in_array($user['invitation_status'], ['pending', 'failed', 'sent'], true))
                                        <flux:button wire:click="resend({{ $user['id'] }})" variant="ghost" size="sm">{{ __('config.user_management.actions.resend') }}</flux:button>
                                    @elseif ($user['invitation_status'] === 'accepted')
                                        <flux:button wire:click="resetPassword({{ $user['id'] }})" variant="ghost" size="sm">{{ __('config.user_management.toast.password_reset_action') }}</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.business_groups_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.user_management.business_groups_description') }}</p>
        <form wire:submit="createBusinessGroup" class="mt-5 grid gap-4 sm:grid-cols-[1fr_1.5fr_auto] sm:items-end">
            <flux:input wire:model="businessGroupCode" :label="__('config.user_management.business_group_code')" />
            <flux:input wire:model="businessGroupName" :label="__('config.user_management.business_group_name')" />
            <flux:button type="submit" variant="primary">{{ __('config.user_management.actions.create_business_group') }}</flux:button>
        </form>
        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('config.user_management.table.business_group') }}</th><th>{{ __('config.user_management.table.status') }}</th></tr></thead>
                <tbody>
                    @forelse ($businessGroups as $group)
                        <tr>
                            <td><strong>{{ $group['code'] }}</strong><br><span class="text-xs text-zinc-500">{{ $group['name'] }}</span></td>
                            <td>{{ $group['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="2">{{ __('config.user_management.empty.business_groups') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.members_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.user_management.members_description') }}</p>
        @php($selectedMembershipUser = collect($unassignedUsers)->firstWhere('id', (int) $membershipUserId))
        <form wire:submit="assignMember" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model="membershipGroupId" :label="__('config.user_management.business_group')">
                <flux:select.option value="">{{ __('config.user_management.select') }}</flux:select.option>
                @foreach ($businessGroups as $group)
                    @if ($group['is_active'])
                        <flux:select.option value="{{ $group['id'] }}">{{ $group['code'] }} · {{ $group['name'] }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:select wire:model="membershipUserId" :label="__('config.user_management.member_user')">
                <flux:select.option value="">{{ __('config.user_management.select') }}</flux:select.option>
                @foreach ($unassignedUsers as $user)
                        <flux:select.option value="{{ $user['id'] }}">{{ $user['name'] }} · {{ __('config.user_management.roles.'.$user['role']) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input
                :value="$selectedMembershipUser === null ? __('config.user_management.select') : __('config.user_management.roles.'.$selectedMembershipUser['role'])"
                :label="__('config.user_management.current_role')"
                readonly
            />
            <flux:input wire:model="membershipEffectiveFrom" type="date" :label="__('config.user_management.effective_from')" />
            <flux:input wire:model="membershipEffectiveUntil" type="date" :label="__('config.user_management.effective_until')" />
            <flux:input wire:model="membershipReason" :label="__('config.user_management.reason')" class="sm:col-span-2" />
            <div class="flex items-end"><flux:button type="submit" variant="primary">{{ __('config.user_management.actions.assign_member') }}</flux:button></div>
        </form>
        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('config.user_management.table.business_group') }}</th><th>{{ __('config.user_management.table.user') }}</th><th>{{ __('config.user_management.table.role') }}</th><th>{{ __('config.user_management.table.effective_period') }}</th><th>{{ __('config.user_management.reason') }}</th></tr></thead>
                <tbody>
                    @forelse ($memberships as $membership)
                        <tr>
                            <td><strong>{{ $membership['group_code'] }}</strong><br><span class="text-xs text-zinc-500">{{ $membership['group_name'] }}</span></td>
                            <td>{{ $membership['user_name'] }}</td>
                            <td>{{ __('config.user_management.roles.'.$membership['member_role']) }}</td>
                            <td>{{ $membership['effective_from'] }} — {{ $membership['effective_until'] ?? __('config.user_management.open_ended') }} @if ($membership['is_current'])<span class="text-xs text-teal-700">({{ __('config.user_management.current') }})</span>@endif</td>
                            <td>{{ $membership['reason'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('config.user_management.empty.memberships') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.agent_assignments_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.user_management.agent_assignments_description') }}</p>
        <form wire:submit="assignAgent" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select wire:model="assignmentAgentId" :label="__('config.user_management.agent')">
                <flux:select.option value="">{{ __('config.user_management.select') }}</flux:select.option>
                @foreach ($agents as $agent)
                    <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model="assignmentGroupId" :label="__('config.user_management.business_group')">
                <flux:select.option value="">{{ __('config.user_management.select') }}</flux:select.option>
                @foreach ($businessGroups as $group)
                    @if ($group['is_active'])
                        <flux:select.option value="{{ $group['id'] }}">{{ $group['code'] }} · {{ $group['name'] }}</flux:select.option>
                    @endif
                @endforeach
            </flux:select>
            <flux:input wire:model="assignmentEffectiveFrom" type="date" :label="__('config.user_management.effective_from')" />
            <flux:input wire:model="assignmentEffectiveUntil" type="date" :label="__('config.user_management.effective_until')" />
            <flux:input wire:model="assignmentReason" :label="__('config.user_management.reason')" class="sm:col-span-2" />
            <div class="flex items-end"><flux:button type="submit" variant="primary">{{ __('config.user_management.actions.assign_agent') }}</flux:button></div>
        </form>
        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('config.user_management.table.agent') }}</th><th>{{ __('config.user_management.table.business_group') }}</th><th>{{ __('config.user_management.table.effective_period') }}</th><th>{{ __('config.user_management.reason') }}</th></tr></thead>
                <tbody>
                    @forelse ($agentAssignments as $assignment)
                        <tr>
                            <td><strong>{{ $assignment['agent_code'] }}</strong><br><span class="text-xs text-zinc-500">{{ $assignment['agent_name'] }}</span></td>
                            <td>{{ $assignment['group_code'] }} · {{ $assignment['group_name'] }}</td>
                            <td>{{ $assignment['effective_from'] }} — {{ $assignment['effective_until'] ?? __('config.user_management.open_ended') }}</td>
                            <td>{{ $assignment['reason'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">{{ __('config.user_management.empty.agent_assignments') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-900 dark:bg-amber-950/30">
        <h3 class="font-semibold">{{ __('config.user_management.integrity_heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ __('config.user_management.integrity_description') }}</p>
        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
                <h4 class="font-semibold">{{ __('config.user_management.unassigned_users') }} ({{ count($unassignedUsers) }})</h4>
                @if (count($unassignedUsers) > 0)
                    <ul class="mt-2 list-disc pl-5 text-sm">@foreach ($unassignedUsers as $user)<li>{{ $user['name'] }} · {{ $user['email'] }}</li>@endforeach</ul>
                @else
                    <p class="mt-2 text-sm">{{ __('config.user_management.integrity_clear') }}</p>
                @endif
            </div>
            <div>
                <h4 class="font-semibold">{{ __('config.user_management.unassigned_agents') }} ({{ count($unassignedAgents) }})</h4>
                @if (count($unassignedAgents) > 0)
                    <ul class="mt-2 list-disc pl-5 text-sm">@foreach ($unassignedAgents as $agent)<li>{{ $agent['code'] }} · {{ $agent['name'] }}</li>@endforeach</ul>
                @else
                    <p class="mt-2 text-sm">{{ __('config.user_management.integrity_clear') }}</p>
                @endif
            </div>
        </div>
    </section>
</div>
