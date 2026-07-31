<div>
    <section class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="crm-eyebrow">Order · 日常订单管理</p>
            <h2>订单管理</h2>
            <p>统一录入和查询订单；订单完成时同步固化推广费与术后提醒。</p>
        </div>
        <flux:button wire:click="openCreate" variant="primary" size="sm" icon="plus">新建订单</flux:button>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('status') }}</div>
    @endif
    @error('completion') <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

    @if ($showCreate)
        <section class="mb-6 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm dark:border-teal-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-semibold">新建订单</h3>
                    <p class="mt-1 text-sm text-zinc-500">先选择客户，再填写本次实际成交信息。</p>
                </div>
                <flux:button wire:click="closeCreate" variant="ghost" size="sm" icon="x-mark">关闭</flux:button>
            </div>

            @error('order') <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror

            <form wire:submit="save" class="mt-5">
                <div class="grid gap-5 xl:grid-cols-[20rem_minmax(0,1fr)]">
                    <div>
                        @if ($selectedCustomer)
                            <div class="rounded-xl border border-teal-200 bg-teal-50 p-4 dark:border-teal-800 dark:bg-teal-950">
                                <div class="text-xs text-teal-700 dark:text-teal-300">已选择客户</div>
                                <div class="mt-1 font-semibold">{{ $selectedCustomer['name'] }}</div>
                                <div class="text-sm text-zinc-500">{{ $selectedCustomer['code'] }}</div>
                                <flux:button class="mt-3" wire:click="clearCustomer" type="button" variant="ghost" size="sm">重新选择</flux:button>
                            </div>
                        @else
                            <flux:input wire:model.live.debounce.300ms="customerSearch" label="搜索客户" icon="magnifying-glass" placeholder="输入客户姓名或编号" />
                            @error('customerId') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
                            <div class="mt-3 max-h-72 space-y-2 overflow-y-auto">
                                @forelse ($customerCandidates as $customer)
                                    <button
                                        type="button"
                                        wire:click="selectCustomer({{ $customer['id'] }})"
                                        class="w-full rounded-xl border border-zinc-200 px-3 py-2 text-left transition hover:border-teal-400 hover:bg-teal-50 dark:border-zinc-700 dark:hover:bg-teal-950"
                                    >
                                        <span class="block font-semibold">{{ $customer['name'] }}</span>
                                        <span class="text-xs text-zinc-500">{{ $customer['code'] }}</span>
                                    </button>
                                @empty
                                    <p class="rounded-xl bg-zinc-50 px-3 py-4 text-center text-sm text-zinc-500 dark:bg-zinc-800">没有匹配客户。</p>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <flux:select wire:model="institutionId" label="机构" required>
                            <flux:select.option value="">请选择</flux:select.option>
                            @foreach ($options['institutions'] as $institution)
                                <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="channel" label="订单渠道" required>
                            <flux:select.option value="agent">代理商</flux:select.option>
                            <flux:select.option value="direct">直销</flux:select.option>
                        </flux:select>
                        @if ($channel === 'agent')
                            <flux:select wire:model="agentId" label="代理商" required>
                                <flux:select.option value="">请选择</flux:select.option>
                                @foreach ($options['agents'] as $agent)
                                    <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @else
                            <flux:select wire:model="directSalesSourceId" label="直销来源" required>
                                <flux:select.option value="">请选择</flux:select.option>
                                @foreach ($options['direct_sources'] as $source)
                                    <flux:select.option value="{{ $source['id'] }}">{{ $source['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        <flux:select wire:model="treatmentProjectId" label="施术项目字典（可选）">
                            <flux:select.option value="">手工填写 / 未归类</flux:select.option>
                            @foreach ($options['treatment_projects'] as $project)
                                <flux:select.option value="{{ $project['id'] }}">{{ $project['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="projectName" label="项目名称快照" :required="$treatmentProjectId === ''" />
                        <flux:input wire:model="amountKrw" type="number" min="0" step="1" label="成交金额（KRW）" required />
                        <flux:select wire:model.live="orderStatus" label="订单状态" required>
                            <flux:select.option value="pending">待完成</flux:select.option>
                            <flux:select.option value="completed">已完成</flux:select.option>
                        </flux:select>
                        @if ($orderStatus === 'completed')
                            <flux:input wire:model="completedOn" type="datetime-local" label="成交时间" required />
                        @endif
                        <flux:select wire:model="translatorLanguageId" label="翻译语种（可选）">
                            <flux:select.option value="">未选择</flux:select.option>
                            @foreach ($options['translator_languages'] as $language)
                                <flux:select.option value="{{ $language['id'] }}">{{ $language['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="translatorName" label="翻译姓名" />
                        <div class="md:col-span-2 xl:col-span-3">
                            <flux:textarea wire:model="notes" label="备注" rows="2" />
                        </div>
                        <div class="md:col-span-2 xl:col-span-3 flex justify-end">
                            <flux:button type="submit" variant="primary">保存订单</flux:button>
                        </div>
                    </div>
                </div>
            </form>
        </section>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        @php
            $selectedInstitution = collect($options['institutions'])->firstWhere('id', (int) $institutionFilter);
            $selectedAgent = collect($options['agents'])->firstWhere('id', (int) $agentFilter);
            $hasFilters = $search !== '' || $statusFilter !== '' || $channelFilter !== '' || $institutionFilter !== '' || $agentFilter !== '' || $perPage !== 20;
        @endphp
        <div class="flex flex-wrap items-center gap-2">
            <flux:input class="mr-1 w-full sm:w-72" wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="搜索订单号、客户或项目" size="sm" />
            <flux:select class="w-32" wire:model.live="statusFilter" size="sm" aria-label="订单状态筛选">
                <flux:select.option value="">全部状态</flux:select.option>
                <flux:select.option value="pending">待完成</flux:select.option>
                <flux:select.option value="completed">已完成</flux:select.option>
            </flux:select>
            <flux:select class="w-32" wire:model.live="channelFilter" size="sm" aria-label="订单渠道筛选">
                <flux:select.option value="">全部渠道</flux:select.option>
                <flux:select.option value="agent">代理商</flux:select.option>
                <flux:select.option value="direct">直销</flux:select.option>
            </flux:select>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedInstitution['name'] ?? '全部机构' }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('institutionFilter', '')">全部机构</flux:menu.item>
                    @foreach ($options['institutions'] as $institution)
                        <flux:menu.item wire:click="$set('institutionFilter', '{{ $institution['id'] }}')">{{ $institution['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $selectedAgent['name'] ?? '全部代理商' }}</flux:button>
                <flux:menu class="max-h-72 overflow-y-auto">
                    <flux:menu.item wire:click="$set('agentFilter', '')">全部代理商</flux:menu.item>
                    @foreach ($options['agents'] as $agent)
                        <flux:menu.item wire:click="$set('agentFilter', '{{ $agent['id'] }}')">{{ $agent['name'] }}</flux:menu.item>
                    @endforeach
                </flux:menu>
            </flux:dropdown>
            <flux:dropdown>
                <flux:button class="rounded-full bg-zinc-100 dark:bg-zinc-800" variant="ghost" size="sm" icon:trailing="chevron-down">{{ $perPage }} 条/页</flux:button>
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
                <thead><tr><th>订单</th><th>客户</th><th>机构 / 渠道</th><th>成交金额</th><th>状态</th><th>时间</th><th></th></tr></thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr wire:key="managed-order-{{ $order['id'] }}">
                            <td><span class="font-semibold">{{ $order['project_name'] }}</span><div class="text-xs text-zinc-500">#{{ $order['id'] }}</div></td>
                            <td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer_id']) }}" wire:navigate>{{ $order['customer_name'] }}</a><div class="text-xs text-zinc-500">{{ $order['customer_code'] }}</div></td>
                            <td>{{ $order['institution'] }}<div class="text-xs text-zinc-500">{{ $order['channel'] === 'agent' ? '代理商' : '直销' }} · {{ $order['source'] }}</div></td>
                            <td>₩ {{ number_format($order['amount_krw']) }}</td>
                            <td><span class="crm-pill {{ $order['status'] === 'completed' ? 'tone-green' : 'tone-amber' }}">{{ $order['status'] === 'completed' ? '已完成' : '待完成' }}</span></td>
                            <td>{{ $order['completed_at'] ?? $order['created_at'] }}<div class="text-xs text-zinc-500">{{ $order['completed_at'] ? '成交时间' : '创建时间' }}</div></td>
                            <td>
                                @if ($order['status'] === 'pending')
                                    <flux:button wire:click="complete({{ $order['id'] }})" wire:confirm="确认将此订单标记为已完成？完成后会固化推广费和术后提醒，不能在此页面修改。" size="sm">标记完成</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-zinc-500">没有符合条件的订单。</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-5">{{ $orders->links() }}</div>
    </section>
</div>
