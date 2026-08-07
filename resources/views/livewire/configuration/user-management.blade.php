<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
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

    <form wire:submit="invite" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.invite_heading') }}</h3>
        <div class="mt-4 grid gap-4 md:grid-cols-[1fr_1fr_auto_auto]">
            <flux:input wire:model="name" :label="__('config.user_management.name')" />
            <flux:input wire:model="email" type="email" :label="__('config.user_management.email')" />
            <flux:checkbox wire:model="isSuperAdmin" :label="__('config.user_management.super_admin')" class="self-end pb-2" />
            <flux:button type="submit" variant="primary" class="self-end">{{ __('config.user_management.create_invitation') }}</flux:button>
        </div>
    </form>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">{{ __('config.user_management.list_heading') }}</h3>
        <div class="crm-table-wrap mt-4">
            <table class="crm-table">
                <thead><tr><th>{{ __('config.user_management.table.user') }}</th><th>{{ __('config.user_management.table.role') }}</th><th>{{ __('config.user_management.table.account') }}</th><th>{{ __('config.user_management.table.invitation') }}</th><th>{{ __('config.user_management.table.actions') }}</th></tr></thead>
                <tbody>
                    @foreach ($users as $user)
                        <tr>
                            <td><strong>{{ $user['name'] }}</strong><br><span class="text-xs text-zinc-500">{{ $user['email'] }}</span></td>
                            <td>{{ $user['is_super_admin'] ? __('config.user_management.super_admin') : __('config.user_management.internal_user') }}</td>
                            <td>{{ $user['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                            <td>
                                {{ __('config.invitation_status.'.$user['invitation_status']) }}
                                @if ($user['invitation_sent_at'])<br><span class="text-xs text-zinc-500">{{ $user['invitation_sent_at'] }}</span>@endif
                            </td>
                            <td>
                                <div class="flex flex-wrap gap-2">
                                    <flux:button wire:click="toggleRole({{ $user['id'] }}, {{ $user['is_super_admin'] ? 'false' : 'true' }})" variant="ghost" size="sm">
                                        {{ $user['is_super_admin'] ? __('config.user_management.actions.make_internal') : __('config.user_management.actions.make_super_admin') }}
                                    </flux:button>
                                    <flux:button wire:click="toggleActive({{ $user['id'] }}, {{ $user['is_active'] ? 'false' : 'true' }})" variant="ghost" size="sm">
                                        {{ $user['is_active'] ? __('config.user_management.actions.disable') : __('config.user_management.actions.enable') }}
                                    </flux:button>
                                    @if (in_array($user['invitation_status'], ['pending', 'failed', 'sent'], true))
                                        <flux:button wire:click="resend({{ $user['id'] }})" variant="ghost" size="sm">{{ __('config.user_management.actions.resend') }}</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
