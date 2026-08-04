@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <img class="crm-shared-logo size-6" src="{{ asset('images/lightyear18-logo.png') }}" alt="光年拾捌 Lightyear 18">
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <img class="crm-shared-logo size-6" src="{{ asset('images/lightyear18-logo.png') }}" alt="光年拾捌 Lightyear 18">
        </x-slot>
    </flux:brand>
@endif
