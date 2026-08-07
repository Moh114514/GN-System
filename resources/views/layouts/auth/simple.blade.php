<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ request()->cookie('flux_resolved_appearance') === 'dark' ? 'dark' : '' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-auth-body">
        <main class="crm-auth-shell">
            <a href="{{ route('home') }}" class="crm-auth-brand" wire:navigate>
                <x-theme-logo mode="light" class="crm-brand-logo crm-auth-brand-logo" />
                <span>
                    <strong>GN-System</strong>
                    <small>{{ __('navigation.brand_tagline') }}</small>
                </span>
            </a>

            <section class="crm-auth-card">
                {{ $slot }}
            </section>

            <p class="crm-auth-footnote">
                <flux:icon.shield-check aria-hidden="true" />
                {{ __('auth.security_notice') }}
            </p>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
