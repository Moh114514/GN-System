<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="crm-section-header">
        <div><p class="text-xs font-medium text-zinc-400">配置中心 · 来源字典</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">直销来源配置</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">停用来源不再出现在新建客户下拉框中，历史客户来源保持不变。</p></div>
    </section>
    @if (session('status'))<div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>@endif
    <section class="grid gap-6 xl:grid-cols-[22rem_1fr]">
        <form wire:submit="save" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ $editingId === null ? '新增来源' : '编辑来源' }}</h3>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="code" label="来源代码（2–6 位）" />
                <flux:input wire:model="name" label="来源名称" />
                <flux:button type="submit" variant="primary">保存</flux:button>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>代码</th><th>名称</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                        @foreach ($sources as $source)
                            <tr>
                                <td>{{ $source['code'] }}</td><td>{{ $source['name'] }}</td><td>{{ $source['is_active'] ? '启用' : '停用' }}</td>
                                <td class="space-x-2">
                                    <flux:button wire:click="edit({{ $source['id'] }})" variant="ghost" size="sm">编辑</flux:button>
                                    <flux:button wire:click="toggle({{ $source['id'] }})" variant="ghost" size="sm">{{ $source['is_active'] ? '停用' : '启用' }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
