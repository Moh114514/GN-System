<div>
    <x-page-back :href="route('configuration.users')" label="返回用户管理" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 安全审计</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">全局审计日志</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">仅展示已脱敏的允许字段；不显示密码、令牌、邮箱、联系方式或完整 IP 地址。</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-3">
            <flux:input wire:model.live="occurredOn" type="date" label="日期" class="w-40" />
            <flux:select wire:model.live="causerId" label="操作者" class="w-40">
                <flux:select.option value="">全部操作者</flux:select.option>
                @foreach ($options['users'] as $user)
                    <flux:select.option value="{{ $user['id'] }}">{{ $user['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="targetUserId" label="目标用户" class="w-40">
                <flux:select.option value="">全部目标用户</flux:select.option>
                @foreach ($options['users'] as $user)
                    <flux:select.option value="{{ $user['id'] }}">{{ $user['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="module" label="模块" class="w-36">
                <flux:select.option value="">全部模块</flux:select.option>
                @foreach ($options['modules'] as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="action" label="动作" class="w-36">
                <flux:select.option value="">全部动作</flux:select.option>
                @foreach ($options['actions'] as $option)
                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="perPage" label="每页" class="w-28">
                @foreach ([20, 50, 100] as $size)
                    <flux:select.option value="{{ $size }}">{{ $size }} 条</flux:select.option>
                @endforeach
            </flux:select>
            @if ($occurredOn !== '' || $causerId !== '' || $targetUserId !== '' || $module !== '' || $action !== '' || $perPage !== 20)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">清除筛选</flux:button>
            @endif
        </div>

        @php($users = collect($options['users'])->keyBy('id'))
        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead><tr><th>时间</th><th>操作者</th><th>目标用户</th><th>模块</th><th>动作</th><th>说明</th><th>允许属性</th></tr></thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr wire:key="audit-log-{{ $entry->id }}">
                            <td>{{ $entry->occurredAt->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $entry->causerName ?? '系统' }}</td>
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
                        <tr><td colspan="7" class="crm-table-empty">没有符合条件的审计记录。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $entries->links() }}</div>
    </section>
</div>
