<div>
    <x-page-back :href="route('reminders.index')" :label="__('reminders.history.back')" class="mb-4" />
    <section class="mb-5"><p class="text-xs font-medium text-zinc-400">{{ __('reminders.history.eyebrow') }}</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('reminders.titles.history') }}</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('reminders.history.description') }}</p></section>
    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="crm-table-wrap"><table class="crm-table"><thead><tr><th>{{ __('reminders.history.reminder') }}</th><th>{{ __('reminders.history.customer') }}</th><th>{{ __('reminders.history.due_at') }}</th><th>{{ __('reminders.history.status') }}</th><th>{{ __('reminders.history.completed_at') }}</th></tr></thead><tbody>
            @forelse ($reminders as $reminder)
                <tr><td>{{ $reminder->title }}</td><td class="font-semibold">{{ $customerNames[$reminder->customer_id] ?? __('reminders.history.unknown_customer') }}</td><td>{{ $reminder->due_at->format('Y-m-d H:i') }}</td><td>{{ __('reminders.statuses.'.$reminder->status) }}</td><td>{{ $reminder->completed_at?->format('Y-m-d H:i') ?? '—' }}</td></tr>
            @empty<tr><td colspan="5" class="py-8 text-center text-zinc-500">{{ __('reminders.history.empty') }}</td></tr>@endforelse
        </tbody></table></div><div class="mt-5">{{ $reminders->links() }}</div>
    </section>
</div>
