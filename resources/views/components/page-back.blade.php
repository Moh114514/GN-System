@props([
    'href',
    'label' => null,
])

<flux:button
    {{ $attributes->class(['shrink-0 self-start']) }}
    :href="$href"
    data-page-back
    data-page-back-path="{{ parse_url($href, PHP_URL_PATH) }}"
    variant="primary"
    color="blue"
    size="base"
    icon="arrow-left"
    wire:navigate
>
    {{ $label ?? __('common.back_to_parent') }}
</flux:button>
