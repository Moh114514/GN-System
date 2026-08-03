<div>
    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">配置中心</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">集中维护系统当前已开放的业务规则与基础配置。</p>
        </div>
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        <a
            href="{{ route('direct-sales-sources.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-trending-up aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">直销来源配置</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">维护直销来源的名称、代码和启停状态。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入配置<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('configuration.history') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.clock aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">配置历史与回滚</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">集中查看各项配置的保存版本、修改差异和回滚记录。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">查看历史<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('configuration.users') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.user-group aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">内部用户与权限</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">邀请用户、分配角色、启停账号并查看邮件发送状态。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入管理<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('configuration.catalog') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.building-library aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">机构、字典与系统参数</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">维护机构联系信息、施术项目、翻译语种和报表参数。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入配置<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
        <a
            href="{{ route('customer-statuses.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.users aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">客户生命周期状态配置</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">维护客户阶段、状态显示、启用规则和允许的流转路径。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">
                进入配置
                <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
            </span>
        </a>

        <a
            href="{{ route('agent-configuration.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.building-office aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">代理商与推广费配置</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">维护代理商类型、政策等级、机构费率和代理商特批。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">
                进入配置
                <flux:icon.arrow-right class="size-4 transition group-hover:translate-x-0.5" aria-hidden="true" />
            </span>
        </a>

        <a
            href="{{ route('reminder-configuration.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.bell-alert aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">主动提醒规则与模板</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">维护固定触发类型、适用范围、建议方向和全局提醒模板。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入配置<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>

        <a
            href="{{ route('reference-configuration-imports.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300">
                <flux:icon.arrow-up-tray aria-hidden="true" />
            </span>
            <h3 class="mt-5 text-lg font-semibold">基础配置导入</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">通过单个 XLSX 预览、检查并批量维护基础字典、政策、费率、代理商和等级分配。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入导入<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
    </section>
</div>
