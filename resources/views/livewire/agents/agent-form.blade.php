<div>
    <x-page-back
        :href="$agentId ? route('agents.show', $agentId) : route('agents.index')"
        :label="$agentId ? '返回代理商详情' : '返回代理商管理'"
        class="mb-4"
    />

    <section class="mb-6">
        <p class="crm-eyebrow">代理商档案</p>
        <h2>{{ $agentId ? '编辑代理商' : '新建代理商' }}</h2>
        <p>{{ $agentId ? '代理商编号建立后永久不变；等级调整从下月起生效。' : '建立合作档案并分配当前政策等级。' }}</p>
    </section>

    <form wire:submit="save" class="space-y-6">
        @error('form') <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div> @enderror
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">基本资料</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model="typeCodeId" label="代理商类型" required>
                    <flux:select.option value="">请选择</flux:select.option>
                    @foreach ($options['types'] as $type)
                        <flux:select.option value="{{ $type['id'] }}">{{ $type['code'] }} · {{ $type['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($agentId)
                    <flux:input wire:model="code" label="代理商编号（永久不变）" readonly />
                @else
                    <flux:input wire:model="codePrefix" label="编号简称" description="2–8 位字母或数字，系统自动附加类型代码" required />
                @endif
                <flux:input wire:model="name" label="代理商名称" required />
                <flux:input wire:model="businessRole" label="业务角色" />
                <flux:input wire:model="contactName" label="联系人" />
                <flux:input wire:model="contactValue" label="联系方式" />
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <h3 class="text-lg font-semibold">合作与政策</h3>
            <div class="mt-5 grid gap-5 md:grid-cols-2">
                <flux:select wire:model="policyGradeId" label="政策等级" required>
                    <flux:select.option value="">请选择</flux:select.option>
                    @foreach ($options['systems'] as $system)
                        @foreach (collect($options['grades'])->where('policy_system_id', $system['id']) as $grade)
                            <flux:select.option value="{{ $grade['id'] }}">{{ $system['name'] }} · {{ $grade['name'] }}</flux:select.option>
                        @endforeach
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="cooperationStatus" label="合作状态" required>
                    <flux:select.option value="active">合作中</flux:select.option>
                    <flux:select.option value="paused">暂停</flux:select.option>
                    <flux:select.option value="terminated">已终止（永久只读）</flux:select.option>
                </flux:select>
                <flux:input wire:model="cooperationStartedOn" type="date" label="合作开始日期" required />
                @if ($cooperationStatus === 'terminated')
                    <flux:input wire:model="cooperationEndedOn" type="date" label="合作终止日期" required />
                @endif
                <div class="md:col-span-2"><flux:textarea wire:model="notes" label="备注" rows="4" /></div>
            </div>
        </section>

        <div class="flex justify-end"><flux:button type="submit" variant="primary">保存代理商档案</flux:button></div>
    </form>
</div>
