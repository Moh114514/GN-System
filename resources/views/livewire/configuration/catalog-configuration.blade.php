<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />
    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 基础目录</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">机构、字典与系统参数</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">停用项不会出现在新录入下拉框中；历史记录仍显示保存时的名称。</p>
        </div>
    </section>
    @error('configuration')<div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</div>@enderror

    <section class="grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="saveInstitution" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">{{ $institutionId === null ? '创建机构' : '编辑机构' }}</h3>
            <div class="mt-4 space-y-3">
                <flux:input wire:model="institutionCode" label="机构代码" />
                <flux:input wire:model="institutionName" label="机构名称" />
                <flux:input wire:model="institutionAddress" label="地址" />
                <flux:input wire:model="institutionContactName" label="联系人" />
                <flux:input wire:model="institutionContactValue" label="联系方式" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">保存机构</flux:button>
                    @if ($institutionId !== null)<flux:button type="button" wire:click="cancelInstitution" variant="ghost">取消</flux:button>@endif
                </div>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">机构列表</h3>
            <div class="crm-table-wrap mt-4">
                <table class="crm-table">
                    <thead><tr><th>代码/名称</th><th>联系信息</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                        @foreach ($state['institutions'] as $institution)
                            <tr>
                                <td><strong>{{ $institution['code'] }}</strong><br>{{ $institution['name'] }}<br><span class="text-xs text-zinc-500">{{ $institution['address'] ?: '未填写地址' }}</span></td>
                                <td>{{ $institution['contact_name'] ?: '—' }}<br>{{ $institution['contact_value'] ?: '—' }}</td>
                                <td>{{ $institution['is_active'] ? '启用' : '停用' }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-2">
                                        <flux:button wire:click="editInstitution({{ $institution['id'] }})" variant="ghost" size="sm">编辑</flux:button>
                                        <flux:button wire:click="toggleInstitution({{ $institution['id'] }})" variant="ghost" size="sm">{{ $institution['is_active'] ? '停用' : '启用' }}</flux:button>
                                        <flux:button wire:click="deleteInstitution({{ $institution['id'] }})" wire:confirm="只有未引用机构可以删除，确认继续吗？" variant="ghost" size="sm">删除</flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="mt-6 grid gap-6 xl:grid-cols-[24rem_1fr]">
        <form wire:submit="saveDictionary" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">施术项目 / 翻译语种</h3>
            <div class="mt-4 space-y-3">
                <flux:select wire:model="dictionaryType" label="字典类型">
                    <option value="treatment_project">施术项目</option>
                    <option value="translator_language">翻译语种</option>
                </flux:select>
                <flux:input wire:model="dictionaryCode" label="稳定代码" />
                <flux:input wire:model="dictionaryName" label="显示名称" />
                <flux:button type="submit" variant="primary">保存字典项</flux:button>
            </div>
        </form>
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="font-semibold">字典项</h3>
            <div class="crm-table-wrap mt-4">
                <table class="crm-table">
                    <thead><tr><th>类型</th><th>代码</th><th>名称</th><th>状态</th><th>操作</th></tr></thead>
                    <tbody>
                        @foreach ($state['dictionary_items'] as $item)
                            <tr>
                                <td>{{ $item['type'] === 'treatment_project' ? '施术项目' : '翻译语种' }}</td>
                                <td>{{ $item['code'] }}</td><td>{{ $item['name'] }}</td><td>{{ $item['is_active'] ? '启用' : '停用' }}</td>
                                <td class="space-x-2">
                                    <flux:button wire:click="editDictionary({{ $item['id'] }})" variant="ghost" size="sm">编辑</flux:button>
                                    <flux:button wire:click="toggleDictionary({{ $item['id'] }})" variant="ghost" size="sm">{{ $item['is_active'] ? '停用' : '启用' }}</flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <form wire:submit="saveParameters" class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="font-semibold">全局系统参数白名单</h3>
        <p class="mt-1 text-sm text-zinc-500">提醒、月结等领域参数仍由各自模块维护。</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="reportDefaultPerPage" type="number" min="10" max="200" label="查询默认分页数" />
            <flux:input wire:model="dashboardRefreshSeconds" type="number" min="60" max="3600" label="看板刷新秒数" />
        </div>
        <flux:button type="submit" variant="primary" class="mt-4">保存参数</flux:button>
    </form>
</div>
