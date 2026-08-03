<div>
    <x-page-back
        :href="$customerId ? route('customers.show', $customerId) : route('customers.index')"
        :label="$customerId ? '返回客户详情' : '返回客户管理'"
        class="mb-4"
    />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">客户档案</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ $customerId ? '编辑客户' : '新建客户' }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $customerId ? '客户编号创建后保持不变，敏感字段变更必须再次确认。' : '建档会同时创建首次到店预约并记录操作日志。' }}</p>
        </div>
    </section>

    <form wire:submit="save" class="space-y-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">基本信息</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:input wire:model="name" label="姓名" required />
                <flux:select wire:model="gender" label="性别">
                    <flux:select.option value="">未填写</flux:select.option>
                    <flux:select.option value="女">女</flux:select.option>
                    <flux:select.option value="男">男</flux:select.option>
                    <flux:select.option value="其他">其他</flux:select.option>
                </flux:select>
                <flux:input wire:model="birthDate" type="date" label="出生日期" required />
                <flux:input wire:model="projectIntention" label="意向项目" required />
                <flux:input wire:model="contact" label="联系方式" required />
                <flux:input wire:model="identityDocument" label="护照号/居留证号" required />
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">来源与编号</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model.live="channel" label="客户来源">
                    <flux:select.option value="agent">代理商</flux:select.option>
                    <flux:select.option value="direct">直销</flux:select.option>
                </flux:select>
                @if ($channel === 'agent')
                    <flux:select wire:model="sourceAgentId" label="来源代理商" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($options['agents'] as $agent)
                            <flux:select.option value="{{ $agent['id'] }}">{{ $agent['code'] }} · {{ $agent['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <flux:select wire:model="sourceDirectSalesId" label="直销来源" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($options['direct_sources'] as $source)
                            <flux:select.option value="{{ $source['id'] }}">{{ $source['code'] }} · {{ $source['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($customerId)
                    <flux:input wire:model="confirmedCode" label="客户编号（不可修改）" disabled />
                @else
                    <div class="space-y-3 md:col-span-2">
                        <flux:checkbox wire:model.live="automaticCode" label="按来源自动生成编号" />
                        <div class="flex items-end gap-3">
                            <div class="flex-1"><flux:input wire:model="confirmedCode" label="客户编号" :disabled="$automaticCode" required /></div>
                            @if ($automaticCode)
                                <flux:button type="button" wire:click="refreshCode">生成/刷新</flux:button>
                            @endif
                        </div>
                        <flux:checkbox wire:model="codeConfirmed" label="我已人工复核并确认上述客户编号" />
                    </div>
                @endif
            </div>
        </section>

        @if (! $customerId)
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold">首次到店预约</h3>
                <div class="mt-5 grid gap-5 md:grid-cols-3">
                    <flux:select wire:model="institutionId" label="机构" required>
                        <flux:select.option value="">请选择</flux:select.option>
                        @foreach ($options['institutions'] as $institution)
                            <flux:select.option value="{{ $institution['id'] }}">{{ $institution['name'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="arrivalDate" type="date" label="到店日期" required />
                    <flux:input wire:model="translatorName" label="翻译（选填）" />
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <flux:textarea wire:model="notes" label="备注" rows="4" />
            @if ($duplicateIds)
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    检测到疑似重复客户：{{ implode('、', array_map(fn ($id) => '#'.$id, $duplicateIds)) }}。系统不会自动合并。
                    <div class="mt-2"><flux:checkbox wire:model="duplicateConfirmed" label="我已核对，仍然保存此客户" /></div>
                </div>
            @endif
            @if ($customerId && ($contact !== $originalContact || $identityDocument !== $originalIdentityDocument))
                <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>敏感信息差异：</strong>
                    联系方式“{{ $originalContact }}”→“{{ $contact }}”；
                    证件号“{{ $originalIdentityDocument }}”→“{{ $identityDocument }}”。
                    <div class="mt-2"><flux:checkbox wire:model="sensitiveConfirmation" label="我确认保存上述敏感信息变更" /></div>
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="mt-5 flex justify-end gap-3">
                <flux:button :href="$customerId ? route('customers.show', $customerId) : route('customers.index')" variant="ghost" wire:navigate>取消</flux:button>
                <flux:button type="submit" variant="primary">保存客户</flux:button>
            </div>
        </section>
    </form>
</div>
