<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.configuration_history.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.configuration_history.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.configuration_history.description') }}</p>
        </div>
    </section>
    <section class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>{{ __('config.configuration_history.table.version') }}</th><th>{{ __('config.configuration_history.table.owner') }}</th><th>{{ __('config.configuration_history.table.type') }}</th><th>{{ __('config.configuration_history.table.action') }}</th><th>{{ __('config.configuration_history.table.time') }}</th><th>{{ __('config.configuration_history.table.actions') }}</th></tr></thead>
                    <tbody>
                        @forelse ($history as $entry)
                            <tr>
                                <td>#{{ $entry['id'] }}</td><td>{{ $entry['owner'] }}</td><td>{{ $entry['type'] }}</td>
                                <td>{{ $entry['action'] }}</td><td>{{ $entry['created_at'] }}</td>
                                <td>
                                    <div class="flex gap-2">
                                        <flux:button wire:click="showDiff('{{ $entry['owner'] }}', {{ $entry['id'] }})" variant="ghost" size="sm">{{ __('config.configuration_history.actions.diff') }}</flux:button>
                                        <flux:button wire:click="rollback('{{ $entry['owner'] }}', {{ $entry['id'] }})" :wire:confirm="__('config.configuration_history.rollback_confirm')" variant="ghost" size="sm">{{ __('config.configuration_history.actions.rollback') }}</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-zinc-500">{{ __('config.configuration_history.empty') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ __('config.configuration_history.diff_heading') }}</h3>
            @if ($selectedSnapshotId)
                <p class="mt-1 text-sm text-zinc-500">{{ $selectedOwner }} #{{ $selectedSnapshotId }}</p>
                <div class="mt-4 space-y-2">
                    @foreach ($diff as $table => $change)
                        <div class="rounded-xl border p-3 text-sm {{ $change['changed'] ? 'border-amber-300 bg-amber-50' : 'border-zinc-200' }}">
                            <strong>{{ $table }}</strong>
                            <span class="mt-1 block">{{ __('config.configuration_history.target_current_counts', ['target' => $change['target_count'], 'current' => $change['current_count']]) }}</span>
                            <span>{{ $change['changed'] ? __('config.configuration_history.changed') : __('config.configuration_history.unchanged') }}</span>
                            @if ($change['changed'])
                                <details class="mt-2">
                                    <summary class="cursor-pointer font-semibold">{{ __('config.configuration_history.expand_values') }}</summary>
                                    <div class="mt-2 grid gap-2">
                                        <div>
                                            <span class="font-semibold">{{ __('config.configuration_history.current_value') }}</span>
                                            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-white p-2 text-xs">{{ json_encode($change['current'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div>
                                            <span class="font-semibold">{{ __('config.configuration_history.target_snapshot') }}</span>
                                            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-white p-2 text-xs">{{ json_encode($change['target'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-zinc-500">{{ __('config.configuration_history.select_version') }}</p>
            @endif
        </aside>
    </section>
</div>
