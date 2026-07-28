<div>
    <x-page-back :href="route('customers.index')" label="返回客户管理" class="mb-4" />

    @if (session('status'))
        <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="space-y-6">
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold">客户档案</h2>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="crm-pill tone-blue">{{ $customer['current_status'] }}</span>
                        <flux:button :href="route('reminders.create', ['customer' => $customer['id']])" size="sm" icon="bell-alert" wire:navigate>添加跟进提醒</flux:button>
                        <flux:button :href="route('customers.orders', $customer['id'])" size="sm" icon="banknotes" wire:navigate>登记订单</flux:button>
                        <flux:button :href="route('customers.edit', $customer['id'])" size="sm" icon="pencil-square" wire:navigate>编辑档案</flux:button>
                    </div>
                </div>
                <dl class="mt-5 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs text-zinc-500">客户姓名</dt><dd class="mt-1 font-semibold">{{ $customer['name'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">客户编号</dt><dd class="mt-1 font-medium">{{ $customer['code'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">联系方式</dt><dd class="mt-1 font-medium">{{ $customer['contact'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">护照/居留证</dt><dd class="mt-1 font-medium">{{ $customer['identity_document'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">出生日期</dt><dd class="mt-1 font-medium">{{ $customer['birth_date'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">来源类型</dt><dd class="mt-1 font-medium">{{ $customer['original_channel'] === 'agent' ? '代理商' : '直销' }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">意向项目</dt><dd class="mt-1 font-medium">{{ $customer['project_intention'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">建档时间</dt><dd class="mt-1 font-medium">{{ $customer['created_at'] }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">备注</dt><dd class="mt-1 font-medium">{{ $customer['notes'] ?: '—' }}</dd></div>
                </dl>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h3 class="text-lg font-semibold">生命周期时间轴</h3>
                    <flux:select wire:model.live="timelineType" class="w-48">
                        <flux:select.option value="">全部事件</flux:select.option>
                        <flux:select.option value="created">建档</flux:select.option>
                        <flux:select.option value="appointment">预约</flux:select.option>
                        <flux:select.option value="order">订单</flux:select.option>
                        <flux:select.option value="followup">跟进</flux:select.option>
                        <flux:select.option value="status">状态变更</flux:select.option>
                        <flux:select.option value="profile">资料修改</flux:select.option>
                    </flux:select>
                </div>
                <div class="mt-6 space-y-5">
                    @forelse ($timeline as $event)
                        <article class="relative border-l-2 border-teal-200 pl-5" wire:key="{{ $event['type'] }}-{{ $loop->index }}-{{ $event['occurred_at'] }}">
                            <div class="flex flex-wrap justify-between gap-2">
                                <h4 class="font-semibold">{{ $event['title'] }}</h4>
                                <time class="text-xs text-zinc-500">{{ \Carbon\CarbonImmutable::parse($event['occurred_at'])->format('Y-m-d H:i') }}</time>
                            </div>
                            <p class="mt-1 text-sm text-zinc-600">{{ $event['content'] }}</p>
                            <p class="mt-1 text-xs text-zinc-400">
                                {{ $event['institution'] ?? '' }}
                                @if ($event['owner'])
                                    <span>· <strong class="font-semibold">{{ $event['owner'] }}</strong></span>
                                @endif
                            </p>
                        </article>
                    @empty
                        <p class="py-8 text-center text-zinc-500">当前筛选没有事件。</p>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">变更状态</h3>
                <form wire:submit="changeStatus" class="mt-4 space-y-3">
                    <flux:select wire:model="targetStatusId" label="目标状态" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($options['statuses'] as $status)
                            <flux:select.option value="{{ $status['id'] }}">{{ $status['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:textarea wire:model="statusReason" label="变更原因" rows="3" required />
                    <flux:button type="submit" variant="primary" class="w-full">确认变更</flux:button>
                </form>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="font-semibold">登记跟进</h3>
                <form wire:submit="recordFollowup" class="mt-4 space-y-3">
                    <flux:input wire:model="followupType" label="跟进类型" required />
                    <flux:input wire:model="followedUpOn" type="date" label="跟进日期" required />
                    <flux:textarea wire:model="followupContent" label="跟进内容" rows="4" required />
                    <flux:button type="submit" class="w-full">保存跟进</flux:button>
                </form>
            </section>
        </aside>
    </div>
</div>
