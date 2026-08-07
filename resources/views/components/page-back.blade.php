@props([
    'href',
    'label' => null,
])

<flux:button
    {{ $attributes->class(['shrink-0 self-start']) }}
    :href="$href"
    variant="primary"
    color="blue"
    size="base"
    icon="arrow-left"
    wire:navigate
>
    {{ $label ?? __('common.back_to_parent') }}
</flux:button>
