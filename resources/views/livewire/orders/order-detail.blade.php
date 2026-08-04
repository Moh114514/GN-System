<div>
    <x-page-back :href="route('orders.index')" label="返回订单管理" class="mb-4" />

    @php
        $deleted = $order['deleted_at'] !== null;
        $statusLabels = ['pending' => '待完成', 'completed' => '已完成', 'cancelled' => '已取消'];
        $statusTone = ['pending' => 'tone-amber', 'completed' => 'tone-green', 'cancelled' => 'tone-red'];
        $isAdmin = (bool) auth()->user()?->is_super_admin;
    @endphp

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">订单管理 · #{{ $order['id'] }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $order['project_name'] }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">查看订单信息、推广费快照、关联提醒和操作记录。</p>
        </div>
        <span class="crm-pill {{ $deleted ? 'tone-red' : ($statusTone[$order['status']] ?? 'tone-blue') }}">{{ $deleted ? '已删除' : ($statusLabels[$order['status']] ?? $order['status']) }}</span>
    </section>


    <section id="status-editor" class="crm-card mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">编辑订单状态</h3>
                <p class="mt-1 text-sm text-zinc-500">状态变更会记录审计；取消和重新打开需要超级管理员填写原因。</p>
            </div>
            @if ($deleted)
                <span class="text-sm text-zinc-500">回收站订单请先恢复后再重新打开。</span>
            @elseif ($order['status'] === 'completed' && ! $isAdmin)
                <span class="text-sm text-zinc-500">已完成订单仅允许超级管理员填写原因后受控回退。</span>
            @elseif ($order['status'] === 'pending' || $isAdmin)
                <div class="flex flex-wrap items-end gap-2">
                    <flux:select wire:model.live="statusSelection" label="订单状态" class="min-w-48">
                        @if ($order['status'] === 'pending')
                            <flux:select.option value="pending">待完成</flux:select.option>
                            <flux:select.option value="completed">已完成</flux:select.option>
                            @if ($isAdmin)<flux:select.option value="cancelled">已取消</flux:select.option>@endif
                        @elseif ($order['status'] === 'completed' && $isAdmin)
                            <flux:select.option value="completed">已完成</flux:select.option>
                            <flux:select.option value="pending">待完成</flux:select.option>
                        @elseif ($order['status'] === 'cancelled' && $isAdmin)
                            <flux:select.option value="cancelled">已取消</flux:select.option>
                            <flux:select.option value="pending">待完成</flux:select.option>
                        @endif
                    </flux:select>
                    <flux:button wire:click="changeStatus" variant="primary">保存状态</flux:button>
                </div>
            @endif
        </div>
        @if (! $deleted && $isAdmin && in_array($statusSelection, ['cancelled', 'pending'], true) && $statusSelection !== $order['status'])
            <div class="mt-4 max-w-xl">
                <flux:textarea wire:model="reason" label="状态变更原因" rows="2" />
            </div>
        @endif
        @error('statusSelection') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
    </section>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(18rem,0.65fr)]">
        <div class="space-y-6">
            <section class="crm-card">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><h3 class="font-semibold">订单信息</h3><p class="mt-1 text-sm text-zinc-500">订单创建后，客户关联保持不变。</p></div>
                    <div class="flex flex-wrap gap-2">
                        @if (! $deleted && $order['status'] === 'pending')
                            <flux:button href="{{ route('orders.edit', $order['id']) }}" wire:navigate variant="ghost" size="sm">编辑订单</flux:button>
                            <flux:button wire:click="complete" wire:confirm="确认将此订单标记为已完成？完成后会固化推广费和术后提醒。" size="sm">标记完成</flux:button>
                        @endif
                    </div>
                </div>
                <dl class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-zinc-500">客户</dt><dd class="mt-1 font-semibold"><a class="text-teal-700 hover:underline" href="{{ route('customers.show', $order['customer']['id']) }}" wire:navigate>{{ $order['customer']['name'] }}</a><span class="ml-1 text-xs font-normal text-zinc-500">{{ $order['customer']['code'] }}</span></dd></div>
                    <div><dt class="text-xs text-zinc-500">机构</dt><dd class="mt-1 font-medium">{{ $order['institution']['name'] ?? '未知机构' }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">渠道</dt><dd class="mt-1 font-medium">{{ $order['channel'] === 'agent' ? '代理商 · '.($order['agent']['name'] ?? '未知代理商') : '直销 · '.($order['direct_source']['name'] ?? '未知来源') }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">成交金额</dt><dd class="mt-1 font-semibold">₩ {{ number_format($order['amount_krw']) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">完成时间</dt><dd class="mt-1">{{ $order['completed_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">创建时间</dt><dd class="mt-1">{{ $order['created_at'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">翻译</dt><dd class="mt-1">{{ $order['translator_name'] ?: '—' }}{{ $order['translator_language'] ? ' · '.$order['translator_language'] : '' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-xs text-zinc-500">备注</dt><dd class="mt-1 whitespace-pre-wrap">{{ $order['notes'] ?: '—' }}</dd></div>
                </dl>
            </section>

            @if ($order['status'] === 'completed')
                <section class="crm-card">
                    <h3 class="font-semibold">推广费与月结</h3>
                    @if ($order['financial']['commission'])
                        <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div><dt class="text-xs text-zinc-500">推广费</dt><dd class="mt-1 font-semibold">₩ {{ number_format($order['financial']['commission']['amount_krw']) }}</dd></div>
                            <div><dt class="text-xs text-zinc-500">费率</dt><dd class="mt-1">{{ number_format($order['financial']['commission']['rate_bps'] / 100, 2) }}%</dd></div>
                            <div><dt class="text-xs text-zinc-500">月结状态</dt><dd class="mt-1">{{ $order['financial']['settlement'] ? $order['financial']['settlement']['status'] : '尚未进入月结' }}</dd></div>
                        </dl>
                        <p class="mt-4 text-xs text-zinc-500">推广费按完成时的规则快照固化，已完成订单本期不提供修改或冲正。</p>
                    @else
                        <p class="mt-4 text-sm text-zinc-500">该订单暂无推广费快照。</p>
                    @endif
                </section>
            @endif

            <section class="crm-card">
                <h3 class="font-semibold">关联提醒</h3>
                @forelse ($order['reminders'] as $reminder)
                    <div class="mt-4 flex flex-wrap items-start justify-between gap-3 border-b border-zinc-100 pb-3 last:border-0 last:pb-0 dark:border-zinc-800">
                        <div><p class="font-medium">{{ $reminder['title'] }}</p><p class="mt-1 text-sm text-zinc-500">{{ $reminder['due_at'] }} · {{ $reminder['notes'] ?: '无备注' }}</p></div>
                        <span class="crm-pill {{ $reminder['status'] === 'completed' ? 'tone-green' : 'tone-amber' }}">{{ $reminder['status'] === 'completed' ? '已完成' : '待处理' }}</span>
                    </div>
                @empty
                    <p class="mt-4 text-sm text-zinc-500">暂无关联提醒。</p>
                @endforelse
            </section>
        </div>

        <aside class="space-y-6">
            @if ($isAdmin && ! $deleted && $order['status'] === 'cancelled')
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:bg-amber-950/30">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">订单操作</h3>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">重新打开或移入回收站都需要填写原因。</p>
                    <flux:textarea wire:model="reason" label="操作原因" rows="3" class="mt-4" />
                    <div class="mt-4 flex flex-wrap gap-2">
                        <flux:button wire:click="reopen" variant="primary" wire:confirm="确认重新打开该订单？">重新打开</flux:button>
                        <flux:button wire:click="softDelete" variant="danger" wire:confirm="确认将该订单移入回收站？">移入回收站</flux:button>
                    </div>
                </section>
            @elseif ($isAdmin && $deleted)
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:bg-amber-950/30">
                    <h3 class="font-semibold text-amber-900 dark:text-amber-200">回收站订单</h3>
                    <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">恢复后订单仍保持“已取消”，如需继续使用请另行重新打开。</p>
                    <flux:button wire:click="restore" class="mt-4" variant="primary" wire:confirm="确认恢复该订单？">恢复订单</flux:button>
                </section>
            @elseif ($order['status'] === 'cancelled')
                <section class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"><h3 class="font-semibold">取消信息</h3><p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ $order['cancellation_reason'] ?: '未记录原因' }}</p></section>
            @endif

            <section class="crm-card">
                <h3 class="font-semibold">操作记录</h3>
                <div class="mt-4 space-y-4">
                    @forelse ($order['audit'] as $entry)
                        <div class="border-l-2 border-teal-200 pl-3 dark:border-teal-800"><p class="font-medium">{{ $entry['description'] }}</p><p class="mt-1 text-xs text-zinc-500">{{ $entry['occurred_at'] }} · 操作人 #{{ $entry['causer_id'] ?? '系统' }}</p>@if (isset($entry['properties']['reason']))<p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">原因：{{ $entry['properties']['reason'] }}</p>@endif</div>
                    @empty
                        <p class="text-sm text-zinc-500">暂无操作记录。</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</div>
