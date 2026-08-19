@props([
    'value' => '',
    'label' => null,
    'mode' => 'date',
])

{{-- Compatibility alias for callers outside the current view set. --}}
<x-date-time-picker
    :value="$value"
    :label="$label"
    :mode="$mode"
    {{ $attributes }}
/>
