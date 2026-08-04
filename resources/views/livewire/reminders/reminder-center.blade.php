<div>
    <section class="crm-section-header">
        <div><h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">主动提醒</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">集中处理术前、到院、术后及自定义跟进任务；提醒仅发送给内部员工。</p></div>
        <div class="flex gap-2"><flux:button :href="route('reminders.history')" variant="ghost" wire:navigate>提醒历史</flux:button><flux:button :href="route('reminders.create')" icon="plus" variant="primary" wire:navigate>新建提醒</flux:button></div>
    </section>

    @if ($stats)
        <section class="mb-6 grid gap-4 sm:grid-cols-3"><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">待处理</span><strong class="mt-1 block text-2xl">{{ $stats['pending'] }}</strong></div><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">已超期</span><strong class="mt-1 block text-2xl text-red-600">{{ $stats['overdue'] }}</strong></div><div class="rounded-xl border bg-white p-4 dark:bg-zinc-900"><span class="text-sm text-zinc-500">累计完成</span><strong class="mt-1 block text-2xl">{{ $stats['completed'] }}</strong></div></section>
    @endif

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="mb-4 max-w-xs"><flux:select wire:model.live="type" label="提醒类型"><flux:select.option value="">全部类型</flux:select.option><flux:select.option value="appointment">术前/到院</flux:select.option><flux:select.option value="post_treatment">术后系列</flux:select.option><flux:select.option value="date_offset">日期规则</flux:select.option><flux:select.option value="fixed_cycle">周期规则</flux:select.option><flux:select.option value="custom">客服自定义</flux:select.option></flux:select></div>
        <div class="space-y-4">
            @forelse ($reminders as $reminder)
                <article class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold">{{ $reminder->title }}</h3><p class="mt-1 text-sm"><span class="font-semibold">{{ $customerNames[$reminder->customer_id] ?? '未知客户' }}</span> · {{ $reminder->due_at->format('Y-m-d H:i') }}</p><p class="mt-1 text-sm text-zinc-500">{{ $reminder->suggestion ?: '无固定话术，请员工自行填写。' }}</p></div><span class="crm-pill {{ $reminder->due_at->isPast() ? 'tone-red' : 'tone-amber' }}">{{ $reminder->due_at->isPast() ? '已到期' : '待处理' }}</span></div>
                    <div class="mt-4 grid gap-3 lg:grid-cols-4"><flux:input wire:model="actionNotes" label="完成/关闭备注" /><flux:input wire:model="snoozeUntil" type="datetime-local" label="延期至" /><flux:input wire:model="snoozeReason" label="延期原因" /><flux:select wire:model="assigneeId" label="转交给"><flux:select.option value="">请选择</flux:select.option>@foreach ($users as $user)<flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>@endforeach</flux:select></div>
                    <div class="mt-3 flex flex-wrap gap-2"><flux:button wire:click="complete({{ $reminder->id }})" size="sm">标记完成</flux:button><flux:button wire:click="snooze({{ $reminder->id }})" size="sm" variant="ghost">延期</flux:button><flux:button wire:click="transfer({{ $reminder->id }})" size="sm" variant="ghost">转交</flux:button><flux:button wire:click="cancel({{ $reminder->id }})" size="sm" variant="ghost">关闭</flux:button>@if (in_array($reminder->notification_status, ['failed', 'disabled'], true))<flux:button wire:click="retryNotification({{ $reminder->id }})" size="sm" variant="ghost">重试钉钉</flux:button>@endif<span class="ml-auto text-xs text-zinc-500">钉钉：{{ ['pending'=>'待下发','queued'=>'队列中','sent'=>'已发送','failed'=>'发送失败','disabled'=>'未启用'][$reminder->notification_status] ?? $reminder->notification_status }}</span></div>
                </article>
            @empty<p class="py-10 text-center text-zinc-500">当前没有待处理提醒。</p>@endforelse
        </div>
        <div class="mt-5">{{ $reminders->links() }}</div>
    </section>
</div>
