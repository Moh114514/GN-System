<section class="mb-6 space-y-4">
    <div>
        <h3 class="font-semibold text-zinc-900 dark:text-zinc-50">{{ __('config.direct_customer.channels.heading') }}</h3>
        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.direct_customer.channels.description') }}</p>
    </div>
    <div class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="save" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h4 class="font-semibold">{{ $channelId === null ? __('config.direct_customer.channels.create') : __('config.direct_customer.channels.edit') }}</h4>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="code" maxlength="32" :disabled="$channelId !== null" :label="__('config.direct_customer.channels.code')" :description="__('config.direct_customer.channels.code_help')" />
                <flux:input wire:model="name" maxlength="255" :label="__('config.direct_customer.channels.name')" />
                <flux:input wire:model="sortOrder" type="number" min="0" max="32767" :label="__('config.direct_customer.channels.sort_order')" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('config.direct_customer.channels.save') }}</flux:button>
                    @if ($channelId !== null)
                        <flux:button wire:click="cancel">{{ __('config.direct_customer.channels.cancel') }}</flux:button>
                    @endif
                </div>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.direct_customer.channels.code') }}</th><th>{{ __('config.direct_customer.channels.name') }}</th><th>{{ __('config.direct_customer.channels.sort_order') }}</th><th>{{ __('config.direct_customer.channels.status') }}</th><th>{{ __('config.direct_customer.channels.actions') }}</th></tr></thead>
                    <tbody>
                        @forelse ($channels as $channel)
                            <tr wire:key="direct-channel-{{ $channel['id'] }}">
                                <td>{{ $channel['code'] }}</td><td>{{ $channel['name'] }}</td><td>{{ $channel['sort_order'] }}</td>
                                <td>{{ $channel['is_active'] ? __('config.status.enabled') : __('config.status.disabled') }}</td>
                                <td><div class="flex flex-wrap gap-2">
                                    <flux:button size="sm" wire:click="edit({{ $channel['id'] }})">{{ __('config.direct_customer.channels.edit') }}</flux:button>
                                    <flux:button size="sm" wire:click="toggle({{ $channel['id'] }})">{{ $channel['is_active'] ? __('config.direct_customer.channels.disable') : __('config.direct_customer.channels.enable') }}</flux:button>
                                </div></td>
                            </tr>
                        @empty
                            <tr><td colspan="5">{{ __('config.direct_customer.channels.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
