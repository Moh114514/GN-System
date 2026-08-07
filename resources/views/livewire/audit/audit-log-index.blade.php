<div>
    <x-page-back :href="route('configuration.users')" :label="__('audit.index.back')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('audit.index.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('audit.index.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('audit.index.description') }}</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="occurredOn" type="date" :label="__('audit.index.date')" class="w-40" />
            <flux:select wire:model.live="causerId" :label="__('audit.index.causer')" class="w-40">
                <flux:select.option value="">{{ __('audit.index.all_causers') }}</flux:select.option>
                @foreach ($options['users'] as $user)
                    <flux:select.option value="{{ $user['id'] }}">{{ $user['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="targetUserId" :label="__('audit.index.target_user')" class="w-40">
                <flux:select.option value="">{{ __('audit.index.all_target_users') }}</flux:select.option>
                @foreach ($options['users'] as $user)
                    <flux:select.option value="{{ $user['id'] }}">{{ $user['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="module" :label="__('audit.index.module')" class="w-36">
                <flux:select.option value="">{{ __('audit.index.all_modules') }}</flux:select.option>
                @foreach ($options['modules'] as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="action" :label="__('audit.index.action')" class="w-36">
                <flux:select.option value="">{{ __('audit.index.all_actions') }}</flux:select.option>
                @foreach ($options['actions'] as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="perPage" :label="__('audit.index.per_page')" class="w-28">
                @foreach ([20, 50, 100] as $size)
                    <flux:select.option value="{{ $size }}">{{ __('audit.index.per_page_count', ['count' => $size]) }}</flux:select.option>
                @endforeach
            </flux:select>
            @if ($occurredOn !== '' || $causerId !== '' || $targetUserId !== '' || $module !== '' || $action !== '' || $perPage !== 20)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">{{ __('audit.index.clear_filters') }}</flux:button>
            @endif
        </div>

        @php($users = collect($options['users'])->keyBy('id'))
        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>{{ __('audit.index.table.time') }}</th><th>{{ __('audit.index.table.causer') }}</th><th>{{ __('audit.index.table.target_user') }}</th><th>{{ __('audit.index.table.module') }}</th><th>{{ __('audit.index.table.action') }}</th><th>{{ __('audit.index.table.description') }}</th><th>{{ __('audit.index.table.properties') }}</th></tr></thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr wire:key="audit-log-{{ $entry->id }}">
                            <td>{{ $entry->occurredAt->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $entry->causerName ?? __('audit.index.system') }}</td>
                            <td>{{ $entry->targetUserId === null ? '—' : ($users->get($entry->targetUserId)['name'] ?? '#'.$entry->targetUserId) }}</td>
                            <td>{{ $entry->module }}</td>
                            <td>{{ $entry->action }}</td>
                            <td>
                                {{ $entry->description }}
                                @if ($entry->legacyDescription)
                                    <span class="ms-1 text-xs text-zinc-400">({{ __('audit.legacy_original') }})</span>
                                @endif
                            </td>
                            <td>
                                @if ($entry->properties === [])
                                    <span class="text-zinc-400">—</span>
                                @else
                                    <pre class="max-w-80 whitespace-pre-wrap break-all text-xs text-zinc-600 dark:text-zinc-300">{{ json_encode($entry->properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) }}</pre>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="crm-table-empty">{{ __('audit.index.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $entries->links() }}</div>
    </section>
</div>
