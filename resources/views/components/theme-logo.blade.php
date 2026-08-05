@props([
    'mode' => 'theme',
    'alt' => '光年拾捌 Lightyear 18',
])

@if ($mode === 'light')
    <img
        src="{{ asset('images/lightyear18-logo-light.png') }}"
        alt="{{ $alt }}"
        {{ $attributes->class(['crm-shared-logo']) }}
    >
@else
    <img
        src="{{ asset('images/lightyear18-logo-light.png') }}"
        alt="{{ $alt }}"
        {{ $attributes->class(['crm-shared-logo', 'crm-logo-light']) }}
    >
    <img
        src="{{ asset('images/lightyear18-logo-dark.png') }}"
        alt="{{ $alt }}"
        {{ $attributes->class(['crm-shared-logo', 'crm-logo-dark']) }}
    >
@endif
