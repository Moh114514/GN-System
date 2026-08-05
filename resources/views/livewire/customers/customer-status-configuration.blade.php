<div>
    <x-page-back :href="route('configuration.index')" label="返回配置中心" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">配置中心 · 客户配置</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">生命周期状态配置</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">系统内部编码保持不变；可调整显示名称、顺序、启用状态和允许的前进路径。</p>
        </div>
    </section>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">生命周期阶段</h3>
            <div class="mt-5 space-y-3">
                @forelse ($stages as $index => $stage)
                    <div class="grid items-end gap-3 rounded-xl border border-zinc-200 p-4 md:grid-cols-[1fr_8rem_8rem]" wire:key="stage-{{ $stage['id'] }}">
                        <flux:input
                            wire:model="stages.{{ $index }}.name"
                            label="{{ $stage['key'] }}"
                            title="阶段显示名称可以调整；{{ $stage['key'] }} 是系统使用的稳定机器键，不会随名称变化。"
                        />
                        <flux:input
                            wire:model="stages.{{ $index }}.sort_order"
                            type="number"
                            label="排序"
                            title="排序数字越小，生命周期阶段越靠前；数字相同时按机器键稳定排序。"
                        />
                        <flux:checkbox
                            wire:model="stages.{{ $index }}.is_active"
                            label="启用"
                            title="停用阶段后，该阶段不再用于新的业务选择；已有历史数据不会被删除。"
                        />
                    </div>
                @empty
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        尚未初始化生命周期阶段。请先确认当前版本的数据库迁移已执行完成。
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">客户状态与转移</h3>
            <div class="mt-5 space-y-4">
                @forelse ($statuses as $index => $status)
                    <div class="rounded-xl border border-zinc-200 p-4" wire:key="status-{{ $status['id'] }}">
                        <div class="grid items-end gap-3 md:grid-cols-4">
                            <flux:input
                                wire:model="statuses.{{ $index }}.name"
                                label="{{ $status['key'] }}"
                                title="状态显示名称可以调整；{{ $status['key'] }} 是系统使用的稳定机器键，不会随名称变化。"
                            />
                            <flux:select
                                wire:model="statuses.{{ $index }}.stage_id"
                                label="所属阶段"
                                title="决定该客户状态归属哪个生命周期阶段，并随阶段顺序展示。"
                            >
                                @foreach ($stages as $stage)
                                    <flux:select.option value="{{ $stage['id'] }}">{{ $stage['name'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input
                                wire:model="statuses.{{ $index }}.sort_order"
                                type="number"
                                label="排序"
                                title="排序数字越小，同一配置列表中的客户状态越靠前；数字相同时按机器键稳定排序。"
                            />
                            <flux:checkbox
                                wire:model="statuses.{{ $index }}.is_active"
                                label="启用"
                                title="停用状态后，该状态不再用于新的业务选择；已有客户的历史状态不会被删除。"
                            />
                        </div>
                        <div class="mt-4">
                            <p
                                class="mb-2 text-xs font-medium text-zinc-500"
                                title="勾选后，客户可从当前状态前进到对应目标状态；未勾选的转移会被业务规则拒绝。"
                            >允许前进到</p>
                            <div class="flex flex-wrap gap-4">
                                @foreach ($statuses as $target)
                                    @if ($target['id'] !== $status['id'])
                                        <flux:checkbox
                                            wire:model="statuses.{{ $index }}.to_status_ids"
                                            value="{{ $target['id'] }}"
                                            label="{{ $target['name'] }}"
                                            title="允许从“{{ $status['name'] }}”前进到“{{ $target['name'] }}”。"
                                        />
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        尚未初始化客户状态。请先确认当前版本的数据库迁移已执行完成。
                    </div>
                @endforelse
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
