@props([
    'href',
    'label' => '返回上一级',
])

<flux:button
    {{ $attributes->class(['shrink-0 self-start']) }}
    :href="$href"
    variant="ghost"
    size="sm"
    icon="arrow-left"
    wire:navigate
>
    {{ $label }}
</flux:button>
