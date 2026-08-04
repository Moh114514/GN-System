<div>
    <x-page-back :href="route('reminders.index')" label="返回主动提醒" class="mb-4" />
    <section class="mb-5"><p class="text-xs font-medium text-zinc-400">客户跟进</p><h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">新建提醒</h2><p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">创建一次性或周期提醒，也可从模板快速开始。</p></section>
    <form wire:submit="save" class="max-w-3xl space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 sm:grid-cols-2"><flux:select wire:model.live="templateId" label="提醒模板"><flux:select.option value="">不使用模板</flux:select.option>@foreach ($templates as $template)<flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="customerId" label="关联客户" required><flux:select.option value="">请选择</flux:select.option>@foreach ($customers as $customer)<flux:select.option value="{{ $customer->id }}">{{ $customer->name }}</flux:select.option>@endforeach</flux:select></div>
        <div class="grid gap-4 sm:grid-cols-2"><flux:input wire:model="title" label="提醒标题" required /><flux:select wire:model="assignedTo" label="负责人" required>@foreach ($users as $user)<flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>@endforeach</flux:select></div>
        <flux:input wire:model="dueAt" type="datetime-local" label="提醒时间" required />
        <flux:input wire:model="suggestion" label="建议方向（不是固定话术）" />
        <flux:textarea wire:model="notes" label="空白话术/工作备注" rows="3" />
        <div class="grid gap-4 sm:grid-cols-2"><flux:select wire:model="recurrenceUnit" label="重复周期"><flux:select.option value="">仅一次</flux:select.option><flux:select.option value="day">每 N 天</flux:select.option><flux:select.option value="week">每 N 周</flux:select.option><flux:select.option value="month">每 N 月</flux:select.option></flux:select><flux:input wire:model="recurrenceInterval" type="number" min="1" label="周期数 N" /></div>
        <flux:checkbox wire:model.live="saveAsTemplate" label="保存为我的个人模板" />@if ($saveAsTemplate)<flux:input wire:model="templateName" label="个人模板名称" required />@endif
        <flux:button type="submit" variant="primary">创建提醒</flux:button>
    </form>
</div>
