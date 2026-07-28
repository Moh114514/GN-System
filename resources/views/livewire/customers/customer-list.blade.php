<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="crm-eyebrow">Phase 3 · 客户全生命周期</p>
            <h2>客户管理</h2>
            <p>统一管理客户档案、来源、状态与跟进记录。</p>
        </div>
        <div class="flex shrink-0 gap-2 sm:justify-end">
            @if (auth()->user()->is_super_admin)
                <flux:button :href="route('customer-statuses.index')" variant="ghost" size="sm" wire:navigate>状态配置</flux:button>
            @endif
            <flux:button :href="route('customers.create')" variant="primary" size="sm" icon="plus" wire:navigate>新建客户</flux:button>
        </div>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedStatus = collect($options['statuses'])->firstWhere('id', (int) $statusId);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentId);
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionId);
            $hasFilters = $search !== '' || $statusId !== '' || $agentId !== '' || $institutionId !== '' || $perPage !== 20;
        @endphp

        <div class="flex flex-wrap items-center gap-2">
            <flux:input
                class="mr-1 w-full sm:w-72"
                wire:model.live.debounce.350ms="search"
                icon="magnifying-glass"
                placeholder="搜索姓名、编号或联系方式"
                size="sm"
            />

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedStatus['name'] ?? '全部状态' }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('statusId', '')">全部状态</flux:menu.item>
                    @foreach ($options['statuses'] as $status)
                        <flux:menu.item wire:click="$set('statusId', '{{ $status['id'] }}')">{{ $status['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedAgent['name'] ?? '全部代理商' }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentId', '')">全部代理商</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentId', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $selectedInstitution['name'] ?? '全部机构' }}
                </flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionId', '')">全部机构</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionId', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">
                    {{ $perPage }} 条/页
                </flux:button>
                <flux:menu>
                    @foreach ([20, 50, 100] as $size)
                        <flux:menu.item wire:click="$set('perPage', {{ $size }})">{{ $size }} 条/页</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>

            @if ($hasFilters)
                <flux:button wire:click="clearFilters" variant="ghost" size="sm" icon="x-mark">清除</flux:button>
            @endif
        </div>

        <div class="crm-table-wrap mt-5">
            <table class="crm-table">
                <thead>
                    <tr>
                        <th>客户</th>
                        <th>联系方式</th>
                        <th>证件</th>
                        <th>来源</th>
                        <th>状态</th>
                        <th>建档时间</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr wire:key="customer-{{ $customer['id'] }}">
                            <td>
                                <a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>
                                    {{ $customer['name'] }}
                                </a>
                                <div class="text-xs text-zinc-500">{{ $customer['code'] }}</div>
                            </td>
                            <td>{{ $customer['contact_masked'] }}</td>
                            <td>{{ $customer['document_masked'] }}</td>
                            <td class="font-semibold">{{ $customer['source'] }}</td>
                            <td><span class="crm-pill tone-blue">{{ $customer['status'] }}</span></td>
                            <td>{{ $customer['created_at'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-10 text-center text-zinc-500">没有符合条件的客户。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $customers->links() }}</div>
    </section>
</div>
