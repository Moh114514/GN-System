<div>
    <x-page-back
        :href="route('configuration.index')"
        label="返回配置中心"
        class="mb-4"
    />

    <section class="crm-section-header">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">数据导入与迁移</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">集中处理系统基础配置和历史业务数据。首次迁移时，请先完成基础配置导入，再进行历史数据迁移。</p>
        </div>
    </section>

    <section class="grid gap-5 lg:grid-cols-2">
        <a
            href="{{ route('reference-configuration-imports.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-up-tray aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">基础配置导入</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">批量导入机构、字典、政策等级、费率、代理商和等级分配。建议在历史数据迁移前先完成此步骤。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入基础配置导入<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>

        <a
            href="{{ route('data-imports.index') }}"
            class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-teal-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-teal-700"
            wire:navigate
        >
            <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950 dark:text-teal-300"><flux:icon.arrow-up-tray aria-hidden="true" /></span>
            <h3 class="mt-5 text-lg font-semibold">历史数据迁移</h3>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">导入历史客户、代理商、订单、跟进、推广费和月结等业务数据。系统会先检查基础数据、文件结构、重复项和异常记录。</p>
            <span class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-teal-700 dark:text-teal-300">进入历史数据迁移<flux:icon.arrow-right class="size-4" aria-hidden="true" /></span>
        </a>
    </section>
</div>
