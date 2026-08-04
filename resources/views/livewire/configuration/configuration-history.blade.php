<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 版本记录</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">配置历史与回滚</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">配置版本按代理商、客户、结算分别保存；回滚不会重算历史订单的推广费或已结算内容。</p>
        </div>
    </section>
    @error('rollback')<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror
    <section class="grid gap-6 xl:grid-cols-[1fr_24rem]">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>版本</th><th>所有者</th><th>配置类型</th><th>动作</th><th>时间</th><th>操作</th></tr></thead>
                    <tbody>
                        @forelse ($history as $entry)
                            <tr>
                                <td>#{{ $entry['id'] }}</td><td>{{ $entry['owner'] }}</td><td>{{ $entry['type'] }}</td>
                                <td>{{ $entry['action'] }}</td><td>{{ $entry['created_at'] }}</td>
                                <td>
                                    <div class="flex gap-2">
                                        <flux:button wire:click="showDiff('{{ $entry['owner'] }}', {{ $entry['id'] }})" variant="ghost" size="sm">差异</flux:button>
                                        <flux:button wire:click="rollback('{{ $entry['owner'] }}', {{ $entry['id'] }})" wire:confirm="确认回滚到该版本吗？当前配置会先保存为新快照。" variant="ghost" size="sm">回滚</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-10 text-center text-zinc-500">尚无配置修改快照。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <aside class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">与当前配置的差异</h3>
            @if ($selectedSnapshotId)
                <p class="mt-1 text-sm text-zinc-500">{{ $selectedOwner }} #{{ $selectedSnapshotId }}</p>
                <div class="mt-4 space-y-2">
                    @foreach ($diff as $table => $change)
                        <div class="rounded-xl border p-3 text-sm {{ $change['changed'] ? 'border-amber-300 bg-amber-50' : 'border-zinc-200' }}">
                            <strong>{{ $table }}</strong>
                            <span class="mt-1 block">目标 {{ $change['target_count'] }} 条 / 当前 {{ $change['current_count'] }} 条</span>
                            <span>{{ $change['changed'] ? '存在差异' : '无差异' }}</span>
                            @if ($change['changed'])
                                <details class="mt-2">
                                    <summary class="cursor-pointer font-semibold">展开当前值与目标值</summary>
                                    <div class="mt-2 grid gap-2">
                                        <div>
                                            <span class="font-semibold">当前值</span>
                                            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-white p-2 text-xs">{{ json_encode($change['current'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                        <div>
                                            <span class="font-semibold">目标快照</span>
                                            <pre class="mt-1 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-white p-2 text-xs">{{ json_encode($change['target'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                                        </div>
                                    </div>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <p class="mt-4 text-sm text-zinc-500">选择一个版本查看差异。</p>
            @endif
        </aside>
    </section>
</div>
