<div>
    <x-page-back :href="route('agents.index')" :label="__('agents.detail.back')" class="mb-4" />

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ __('agents.detail.profile') }}</h2>
            <div class="flex items-center gap-2">
                <span class="crm-pill {{ $agent['cooperation_status'] === 'active' ? 'tone-green' : ($agent['cooperation_status'] === 'paused' ? 'tone-amber' : 'tone-red') }}">{{ ['active' => __('agents.form.active'), 'paused' => __('agents.form.paused'), 'terminated' => __('agents.form.terminated')][$agent['cooperation_status']] }}</span>
                @if ($agent['cooperation_status'] !== 'terminated')
                    <flux:button :href="route('agents.edit', $agent['id'])" size="sm" icon="pencil-square" wire:navigate>{{ __('agents.detail.edit') }}</flux:button>
                @endif
            </div>
        </div>
        <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.name') }}</dt><dd class="mt-1 font-semibold">{{ $agent['name'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.code') }}</dt><dd class="mt-1 font-medium">{{ $agent['code'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.type') }}</dt><dd class="mt-1 font-medium">{{ $agent['type'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.business_role') }}</dt><dd class="mt-1 font-medium">{{ $agent['business_role'] ?: __('agents.detail.empty') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.policy_system') }}</dt><dd class="mt-1 font-medium">{{ $agent['policy_system'] ?? __('agents.detail.unset_policy') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.grade') }}</dt><dd class="mt-1 font-medium">{{ $agent['policy_grade'] ?? __('agents.detail.unset_grade') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.contact_name') }}</dt><dd class="mt-1 font-semibold">{{ $agent['contact_name'] ?: __('agents.detail.empty') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.contact_value') }}</dt><dd class="mt-1 font-medium">{{ $agent['contact_value'] ?: __('agents.detail.empty') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.started') }}</dt><dd class="mt-1 font-medium">{{ $agent['cooperation_started_on'] }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.ended') }}</dt><dd class="mt-1 font-medium">{{ $agent['cooperation_ended_on'] ?: __('agents.detail.empty') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.effective_month') }}</dt><dd class="mt-1 font-medium">{{ $agent['grade_effective_month'] ?: __('agents.detail.empty') }}</dd></div>
            <div><dt class="text-xs text-zinc-500">{{ __('agents.detail.notes') }}</dt><dd class="mt-1 font-medium">{{ $agent['notes'] ?: __('agents.detail.empty') }}</dd></div>
        </dl>
    </section>

    <section class="mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h3 class="text-lg font-semibold">{{ __('agents.detail.grade_history') }}</h3>
        <div class="crm-table-wrap mt-4"><table class="crm-table">
            <thead><tr><th>{{ __('agents.detail.effective_month') }}</th><th>{{ __('agents.detail.policy_system') }}</th><th>{{ __('agents.detail.grade') }}</th><th>{{ __('agents.detail.reason') }}</th><th>{{ __('agents.detail.source') }}</th><th>{{ __('agents.detail.history_status') }}</th></tr></thead>
            <tbody>
            @forelse ($agent['grade_history'] as $history)
                <tr>
                    <td>{{ $history['effective_month'] }}</td>
                    <td>{{ $history['policy_system'] ?? __('agents.detail.unset_policy') }}</td>
                    <td>{{ $history['policy_grade'] ?? __('agents.detail.unset_grade') }}</td>
                    <td>{{ $history['reason'] ?: __('agents.detail.empty') }}</td>
                    <td>{{ __('agents.detail.sources.'.$history['source']) }}</td>
                    <td>{{ __('agents.detail.history_statuses.'.$history['status']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-zinc-500">{{ __('agents.detail.no_grade_history') }}</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </section>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('agents.detail.customers') }}</h3>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>{{ __('agents.detail.customer') }}</th><th>{{ __('agents.detail.created_at') }}</th></tr></thead>
                <tbody>
                @forelse ($agent['customers'] as $customer)
                    <tr><td><a class="font-semibold text-teal-700 hover:underline" href="{{ route('customers.show', $customer['id']) }}" wire:navigate>{{ $customer['name'] }}</a><div class="text-xs text-zinc-500">{{ $customer['code'] }}</div></td><td>{{ $customer['created_at'] }}</td></tr>
                @empty
                    <tr><td colspan="2" class="py-8 text-center text-zinc-500">{{ __('agents.detail.no_customers') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">{{ __('agents.detail.orders') }}</h3>
            <div class="crm-table-wrap mt-4"><table class="crm-table">
                <thead><tr><th>{{ __('agents.detail.project_amount') }}</th><th>{{ __('agents.detail.status') }}</th><th>{{ __('agents.detail.promotion_fee') }}</th></tr></thead>
                <tbody>
                @forelse ($agent['orders'] as $order)
                    <tr>
                        <td>{{ $order->projectName }}<div class="text-xs text-zinc-500">₩ {{ number_format($order->amountKrw) }}</div></td>
                        <td>{{ $order->status === 'completed' ? __('agents.detail.completed') : __('agents.detail.pending') }}</td>
                        <td>{{ $order->commissionAmountKrw === null ? __('agents.detail.empty') : '₩ '.number_format($order->commissionAmountKrw) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-8 text-center text-zinc-500">{{ __('agents.detail.no_orders') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</div>
