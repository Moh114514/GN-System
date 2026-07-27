<div>
    <x-page-back :href="route('customers.index')" label="返回客户管理" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="crm-eyebrow">超级管理员 · 客户配置</p>
            <h2>生命周期状态配置</h2>
            <p>机器键保持稳定；可调整显示名称、顺序、启用状态和允许的前进路径。</p>
        </div>
    </section>

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">生命周期阶段</h3>
            <div class="mt-5 space-y-3">
                @foreach ($stages as $index => $stage)
                    <div class="grid items-end gap-3 rounded-xl border border-zinc-200 p-4 md:grid-cols-[1fr_8rem_8rem]" wire:key="stage-{{ $stage['id'] }}">
                        <flux:input wire:model="stages.{{ $index }}.name" label="{{ $stage['key'] }}" />
                        <flux:input wire:model="stages.{{ $index }}.sort_order" type="number" label="排序" />
                        <flux:checkbox wire:model="stages.{{ $index }}.is_active" label="启用" />
                    </div>
                @endforeach
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">客户状态与转移</h3>
            <div class="mt-5 space-y-4">
                @foreach ($statuses as $index => $status)
                    <div class="rounded-xl border border-zinc-200 p-4" wire:key="status-{{ $status['id'] }}">
                        <div class="grid items-end gap-3 md:grid-cols-4">
                            <flux:input wire:model="statuses.{{ $index }}.name" label="{{ $status['key'] }}" />
                            <flux:select wire:model="statuses.{{ $index }}.stage_id" label="所属阶段">
                                @foreach ($stages as $stage)
                                    <flux:select.option value="{{ $stage['id'] }}">{{ $stage['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="statuses.{{ $index }}.sort_order" type="number" label="排序" />
                            <flux:checkbox wire:model="statuses.{{ $index }}.is_active" label="启用" />
                        </div>
                        <div class="mt-4">
                            <p class="mb-2 text-xs font-medium text-zinc-500">允许前进到</p>
                            <div class="flex flex-wrap gap-4">
                                @foreach ($statuses as $target)
                                    @if ($target['id'] !== $status['id'])
                                        <flux:checkbox
                                            wire:model="statuses.{{ $index }}.to_status_ids"
                                            value="{{ $target['id'] }}"
                                            label="{{ $target['name'] }}"
                                        />
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div class="flex justify-end gap-3">
            <flux:button type="submit" variant="primary">保存配置</flux:button>
        </div>
    </form>
</div>
