<div>
    <x-page-back :href="route('reminders.index')" label="返回主动提醒" class="mb-4" />
    <section class="mb-5"><p class="crm-eyebrow">客户跟进</p><h2>提醒历史</h2><p>查看已完成和已关闭的提醒记录。</p></section>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="crm-table-wrap"><table class="crm-table"><thead><tr><th>提醒</th><th>客户</th><th>计划时间</th><th>状态</th><th>完成时间</th></tr></thead><tbody>
            @forelse ($reminders as $reminder)
                <tr><td>{{ $reminder->title }}</td><td class="font-semibold">{{ $customerNames[$reminder->customer_id] ?? '未知客户' }}</td><td>{{ $reminder->due_at->format('Y-m-d H:i') }}</td><td>{{ $reminder->status === 'completed' ? '已完成' : '已关闭' }}</td><td>{{ $reminder->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">暂无历史提醒。</td></tr>@endforelse
        </tbody></table></div><div class="mt-5">{{ $reminders->links() }}</div>
    </section>
</div>
