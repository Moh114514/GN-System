<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-auth-body">
        <main class="crm-auth-shell">
            <a href="{{ route('home') }}" class="crm-auth-brand" wire:navigate>
                <img
                    class="crm-brand-logo crm-auth-brand-logo"
                    src="{{ asset('images/lightyear18-logo.png') }}"
                    alt="光年拾捌 Lightyear 18"
                >
                <span>
                    <strong>GN-System</strong>
                    <small>专业 · 安全 · 高效</small>
                </span>
            </a>

            <section class="crm-auth-card">
                {{ $slot }}
            </section>

            <p class="crm-auth-footnote">
                <flux:icon.shield-check aria-hidden="true" />
                企业级数据安全保护
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
