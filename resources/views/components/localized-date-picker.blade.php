@props([
    'value' => '',
    'label' => null,
    'locale' => app()->getLocale(),
])

@php
    $modelAttributes = $attributes->filter(fn ($attribute, $name) => str_starts_with($name, 'wire:model'));
    $visibleAttributes = $attributes
        ->except(['class', 'id', 'name', 'value', 'type', 'required', 'size', 'onchange', 'oninput', 'wire:key'])
        ->filter(fn ($attribute, $name) => ! str_starts_with($name, 'wire:model'));
    $inputId = $attributes->get('id') ?? 'localized-date-picker-'.substr(md5((string) ($label ?? '').$attributes->get('name', '').$attributes->get('wire:model', '')), 0, 10);
    $valueId = $inputId.'-value';
    $visibleClass = trim('crm-localized-date-picker '.$attributes->get('class', ''));
@endphp

<div
    x-data="localizedDatePicker({ value: @js($value), locale: @js($locale) })"
    x-init="init()"
    @keydown.escape.stop="close()"
    class="relative {{ $label ? 'grid gap-2' : '' }}"
>
    @if ($label)
        <label for="{{ $inputId }}" data-flux-label>{{ $label }}</label>
    @endif

    <input
        id="{{ $valueId }}"
        type="hidden"
        value="{{ $value }}"
        {{ $modelAttributes }}
        {{ $attributes->only(['name', 'required', 'form', 'onchange', 'oninput']) }}
        x-ref="value"
    >

    <button
        id="{{ $inputId }}"
        type="button"
        class="{{ $visibleClass }} inline-flex min-h-9 w-full items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-left text-sm text-zinc-800 shadow-sm transition hover:border-zinc-400 focus:border-accent focus:outline-hidden focus:ring-2 focus:ring-accent focus:ring-offset-2 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
        aria-haspopup="dialog"
        aria-controls="{{ $inputId }}-calendar"
        aria-label="{{ $attributes->get('aria-label', $label) }}"
        title="{{ $attributes->get('title') }}"
        x-bind:aria-expanded="open.toString()"
        @click="toggle()"
        {{ $visibleAttributes->except(['aria-label', 'title']) }}
    >
        <span x-text="displayValue || labels.placeholder" class="truncate"></span>
        <span aria-hidden="true" class="shrink-0 text-zinc-400">▾</span>
    </button>

    <div
        id="{{ $inputId }}-calendar"
        x-cloak
        x-show="open"
        x-transition.origin.top.left
        @click.outside="close()"
        class="absolute left-0 top-full z-50 mt-2 w-72 rounded-xl border border-zinc-200 bg-white p-3 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
        role="dialog"
        aria-modal="false"
        aria-label="{{ $label ?? __('navigation.date_label') }}"
    >
        <div class="mb-3 flex items-center justify-between gap-2">
            <button type="button" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="goMonth(-1)" :aria-label="labels.previousMonth">‹</button>
            <strong class="text-sm text-zinc-800 dark:text-zinc-100" x-text="monthLabel"></strong>
            <button type="button" class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" @click="goMonth(1)" :aria-label="labels.nextMonth">›</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[11px] text-zinc-500" aria-hidden="true">
            <template x-for="weekday in weekdays" :key="weekday"><span x-text="weekday"></span></template>
        </div>
        <div class="mt-1 grid grid-cols-7 gap-1" role="grid">
            <template x-for="day in calendarDays" :key="day.iso">
                <button
                    type="button"
                    role="gridcell"
                    class="aspect-square rounded-md text-xs transition hover:bg-accent/10"
                    :class="day.selected ? 'bg-accent text-white hover:bg-accent' : (day.currentMonth ? 'text-zinc-800 dark:text-zinc-100' : 'text-zinc-300 dark:text-zinc-600')"
                    :aria-label="day.iso"
                    :aria-selected="day.selected.toString()"
                    @click="selectDay(day.iso)"
                    x-text="day.day"
                ></button>
            </template>
        </div>
        <div class="mt-3 flex justify-between border-t border-zinc-100 pt-2 text-xs dark:border-zinc-800">
            <button type="button" class="text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-100" @click="clear()" x-text="labels.clear"></button>
            <button type="button" class="font-medium text-accent-content hover:underline" @click="today()" x-text="labels.today"></button>
        </div>
    </div>
</div>
