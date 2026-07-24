<x-layouts::app title="仪表盘">
    <div class="flex w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">GN-System CRM</flux:heading>
            <flux:text class="mt-2">基础架构已就绪。业务模块将在后续阶段逐步开放。</flux:text>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>客户生命周期</flux:heading>
                <flux:text class="mt-2">客户建档、跟进与回访提醒</flux:text>
                <flux:badge class="mt-4" color="zinc">Phase 2</flux:badge>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>代理商与结算</flux:heading>
                <flux:text class="mt-2">政策等级、推广费核算与月结</flux:text>
                <flux:badge class="mt-4" color="zinc">Phase 4–5</flux:badge>
            </div>
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading>数据分析</flux:heading>
                <flux:text class="mt-2">多维查询与经营数据看板</flux:text>
                <flux:badge class="mt-4" color="zinc">Phase 6</flux:badge>
            </div>
        </div>

        @if(auth()->user()->is_super_admin && auth()->user()->two_factor_confirmed_at === null)
            <flux:callout icon="shield-exclamation" color="amber" heading="需要启用双因素认证">
                <flux:text>超级管理员必须完成双因素认证后才能访问业务功能。</flux:text>
                <x-slot name="actions">
                    <flux:button :href="route('security.edit')" wire:navigate>前往安全设置</flux:button>
                </x-slot>
            </flux:callout>
        @endif
    </div>
</x-layouts::app>
