@props([
    'value' => '',
    'label' => null,
    'mode' => 'date',
])

@php
    $type = match ($mode) {
        'date' => 'date',
        'datetime' => 'datetime-local',
        'time' => 'time',
        default => throw new \InvalidArgumentException('Unsupported date-time picker mode.'),
    };
    $inputAttributes = $attributes
        ->except(['type', 'value'])
        ->merge([
            'type' => $type,
            'value' => $value,
            'aria-label' => $attributes->get('aria-label', $label),
            'input:aria-label' => $attributes->get('aria-label', $label),
            'class' => trim('crm-date-time-picker '.(string) $attributes->get('class', '')),
        ]);
    $attributes = $inputAttributes;
@endphp

<flux:input :label="$label" {{ $attributes }} />
