<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ request()->cookie('flux_resolved_appearance') === 'dark' ? 'dark' : '' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-body" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        <aside
            class="crm-sidebar"
            :class="{ 'is-open': sidebarOpen }"
            aria-label="{{ __('navigation.main') }}"
        >
            <a href="{{ route('dashboard') }}" class="crm-brand" wire:navigate>
                <x-theme-logo class="crm-brand-logo" />
                <span>
                    <strong>GN-System</strong>
                    <small>{{ __('navigation.brand_tagline') }}</small>
                </span>
            </a>

            <nav class="crm-nav">
                <a href="{{ route('dashboard') }}" class="crm-nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.home aria-hidden="true" />
                    <span>{{ __('navigation.dashboard') }}</span>
                </a>

                <a href="{{ route('reminders.index') }}" class="crm-nav-item {{ request()->routeIs('reminders.*') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.bell-alert aria-hidden="true" />
                    <span>{{ __('navigation.reminders') }}</span>
                </a>

                <a href="{{ route('customers.index') }}" class="crm-nav-item {{ request()->routeIs('customers.index', 'customers.create', 'customers.show', 'customers.edit') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.users aria-hidden="true" />
                    <span>{{ __('navigation.customers') }}</span>
                </a>

                <a href="{{ route('orders.index') }}" class="crm-nav-item {{ request()->routeIs('orders.*', 'customers.orders') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.clipboard-document-list aria-hidden="true" />
                    <span>{{ __('navigation.orders') }}</span>
                </a>

                <a href="{{ route('reports.search') }}" class="crm-nav-item {{ request()->routeIs('reports.search', 'reports.exports.*') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.magnifying-glass aria-hidden="true" />
                    <span>{{ __('navigation.reports') }}</span>
                </a>

                @if (auth()->user()->is_super_admin)
                    <a href="{{ route('agents.index') }}" class="crm-nav-item {{ request()->routeIs('agents.*') ? 'is-active' : '' }}" wire:navigate>
                        <flux:icon.building-office aria-hidden="true" />
                        <span>{{ __('navigation.agents') }}</span>
                    </a>
                    <a href="{{ route('settlements.index') }}" class="crm-nav-item {{ request()->routeIs('settlements.*') ? 'is-active' : '' }}" wire:navigate>
                        <flux:icon.banknotes aria-hidden="true" />
                        <span>{{ __('navigation.settlements') }}</span>
                    </a>
                    @php
                        $configurationNavigationActive = request()->routeIs(
                            'configuration.*',
                            'agent-configuration.*',
                            'customer-statuses.*',
                            'direct-sales-sources.*',
                            'reminder-configuration.*',
                            'reference-configuration-imports.*',
                            'data-imports.*',
                        );
                    @endphp

                    <div
                        class="crm-nav-group"
                        x-data="{ open: @js($configurationNavigationActive) }"
                        data-test="configuration-nav-group"
                    >
                        <div class="crm-nav-group-head {{ $configurationNavigationActive ? 'is-active' : '' }}">
                            <a
                                href="{{ route('configuration.index') }}"
                                class="crm-nav-group-link"
                                data-test="configuration-nav-link"
                                wire:navigate
                            >
                                <flux:icon.cog-6-tooth aria-hidden="true" />
                                <span>{{ __('navigation.configuration') }}</span>
                            </a>

                            <button
                                type="button"
                                class="crm-nav-group-toggle"
                                @click="open = !open"
                                :aria-expanded="open"
                                aria-controls="configuration-subnav"
                                aria-label="{{ __('navigation.toggle_configuration') }}"
                                data-test="configuration-nav-toggle"
                            >
                                <flux:icon.chevron-down
                                    class="crm-nav-chevron"
                                    x-bind:class="{ 'is-open': open }"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>

                        <div
                            id="configuration-subnav"
                            class="crm-subnav-collapse {{ $configurationNavigationActive ? 'is-open' : '' }}"
                            aria-hidden="{{ $configurationNavigationActive ? 'false' : 'true' }}"
                            x-bind:class="{ 'is-open': open }"
                            x-bind:aria-hidden="(!open).toString()"
                            @if (! $configurationNavigationActive) inert @endif
                            x-bind:inert="!open"
                        >
                            <div class="crm-subnav-collapse-inner">
                                <div class="crm-subnav">
                                    <a
                                        href="{{ route('configuration.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.index') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-overview"
                                        wire:navigate
                                    >
                                        {{ __('navigation.configuration_overview') }}
                                    </a>

                                    <a
                                        href="{{ route('configuration.catalog') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.catalog') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-catalog"
                                        wire:navigate
                                    >
                                        {{ __('navigation.catalog') }}
                                    </a>

                                    <a
                                        href="{{ route('direct-sales-sources.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('direct-sales-sources.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-direct-sales-sources"
                                        wire:navigate
                                    >
                                        {{ __('navigation.direct_sales_sources') }}
                                    </a>

                                    <a
                                        href="{{ route('customer-statuses.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('customer-statuses.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-customer-statuses"
                                        wire:navigate
                                    >
                                        {{ __('navigation.customer_statuses') }}
                                    </a>

                                    <a
                                        href="{{ route('agent-configuration.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('agent-configuration.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-agent"
                                        wire:navigate
                                    >
                                        {{ __('navigation.agent_configuration') }}
                                    </a>

                                    <a
                                        href="{{ route('reminder-configuration.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('reminder-configuration.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-reminder"
                                        wire:navigate
                                    >
                                        {{ __('navigation.reminder_configuration') }}
                                    </a>

                                    <a
                                        href="{{ route('configuration.users') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.users') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-users"
                                        wire:navigate
                                    >
                                        {{ __('navigation.users') }}
                                    </a>

                                    <a
                                        href="{{ route('configuration.history') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.history') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-history"
                                        wire:navigate
                                    >
                                        {{ __('navigation.history') }}
                                    </a>

                                    <a
                                        href="{{ route('configuration.data-maintenance') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.data-maintenance', 'reference-configuration-imports.*', 'data-imports.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-data-maintenance"
                                        wire:navigate
                                    >
                                        {{ __('navigation.data_maintenance') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

            </nav>

            <div class="crm-sidebar-footer">
                <a href="{{ route('security.edit') }}" class="crm-security-card" wire:navigate>
                    <span class="crm-security-title">
                        <flux:icon.shield-check aria-hidden="true" />
                        {{ __('navigation.security_title') }}
                    </span>
                    <span>{{ __('navigation.security_subtitle') }}</span>
                    <strong>{{ __('navigation.security_link') }} <span aria-hidden="true">›</span></strong>
                </a>
            </div>
        </aside>

        <button
            type="button"
            class="crm-scrim"
            :class="{ 'is-visible': sidebarOpen }"
            @click="sidebarOpen = false"
            aria-label="{{ __('navigation.close_nav') }}"
        ></button>

        <div class="crm-shell">
            <header class="crm-topbar">
                <button
                    type="button"
                    class="crm-icon-button crm-menu-button"
                    @click="sidebarOpen = true"
                    aria-label="{{ __('navigation.open_nav') }}"
                >
                    <flux:icon.bars-3 />
                </button>

                <h1>{{ $title ?? __('common.app_name') }}</h1>
                <div class="crm-topbar-spacer"></div>

                @php
                    $topbarSearchQuery = request()->routeIs('global-search')
                        ? (string) request('q', '')
                        : (request()->routeIs('customers.*', 'agents.*') ? (string) request('search', '') : '');
                @endphp
                <div
                    class="crm-global-search"
                    x-data="{ open: false, query: @js($topbarSearchQuery) }"
                    @click.outside="open = false"
                    @keydown.window.prevent.meta.k="$refs.input.focus(); open = true"
                    @keydown.window.prevent.ctrl.k="$refs.input.focus(); open = true"
                >
                    <form action="{{ route('global-search') }}" method="GET" class="crm-search" @submit="open = false">
                        <flux:icon.magnifying-glass aria-hidden="true" />
                        <label class="sr-only" for="global-search">{{ __('navigation.search_label') }}</label>
                        <input
                            id="global-search"
                            x-ref="input"
                            x-model="query"
                            @focus="open = true"
                            @input="open = true"
                            name="q"
                            type="search"
                            placeholder="{{ __('navigation.search_placeholder') }}"
                            autocomplete="off"
                        >
                        <kbd>⌘ K</kbd>
                    </form>

                    <div class="crm-search-menu" x-cloak x-show="open" role="menu">
                        <a
                            x-bind:href="'{{ route('customers.index') }}?search=' + encodeURIComponent(query)"
                            @click="open = false"
                            role="menuitem"
                        >
                            <flux:icon.users aria-hidden="true" />
                            <span>{{ __('navigation.search_customer') }}<strong x-text="query ? `“${query}”` : ''"></strong></span>
                        </a>
                        <a
                            x-bind:href="'{{ route('reports.search') }}' + (query ? '?projectName=' + encodeURIComponent(query) : '')"
                            @click="open = false"
                            role="menuitem"
                        >
                            <flux:icon.clipboard-document-list aria-hidden="true" />
                            <span>{{ __('navigation.search_order') }}<strong x-text="query ? `“${query}”` : ''"></strong></span>
                        </a>
                        @if (auth()->user()->is_super_admin)
                            <a
                                x-bind:href="'{{ route('agents.index') }}?search=' + encodeURIComponent(query)"
                                @click="open = false"
                                role="menuitem"
                            >
                                <flux:icon.building-office aria-hidden="true" />
                                <span>{{ __('navigation.search_agent') }}<strong x-text="query ? `“${query}”` : ''"></strong></span>
                            </a>
                        @endif
                    </div>
                </div>

                <form action="{{ route('dashboard') }}" method="GET" class="crm-date-form">
                    <label class="crm-date-range">
                        <span class="sr-only">{{ __('navigation.date_label') }}</span>
                        <x-localized-date-picker
                            :value="request()->routeIs('dashboard') ? (string) request('date', now('Asia/Shanghai')->format('Y-m-d')) : now('Asia/Shanghai')->format('Y-m-d')"
                            name="date"
                            data-test="topbar-date-control"
                            :aria-label="__('navigation.date_label')"
                            onchange="this.form.requestSubmit()"
                        />
                    </label>
                </form>

                <a
                    href="{{ route('reminders.index') }}"
                    class="crm-icon-button crm-notification-button"
                    data-test="reminder-notification-button"
                    aria-label="{{ __('navigation.view_reminders') }}"
                    wire:navigate
                >
                    <flux:icon.bell aria-hidden="true" />
                </a>

                <flux:dropdown position="bottom" align="end">
                    <button
                        type="button"
                        class="crm-language-trigger"
                        data-test="topbar-language-button"
                        aria-label="{{ __('navigation.language') }}"
                    >
                        <flux:icon.globe-alt aria-hidden="true" />
                        <span class="crm-language-label">
                            {{ __('language.options.'.app()->getLocale(), [], app()->getLocale()) }}
                        </span>
                        <flux:icon.chevron-down aria-hidden="true" />
                    </button>

                    <flux:menu>
                        @foreach (config('localization.supported', []) as $locale => $label)
                            <form method="POST" action="{{ route('locale.update') }}" class="w-full">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $locale }}">
                                <flux:menu.item
                                    as="button"
                                    type="submit"
                                    class="w-full cursor-pointer"
                                    data-test="topbar-language-option-{{ $locale }}"
                                >
                                    <span class="flex w-full items-center justify-between gap-4">
                                        <span>{{ __('language.options.'.$locale, [], $locale) }}</span>
                                        @if (app()->getLocale() === $locale)
                                            <flux:icon.check class="size-4" aria-hidden="true" />
                                        @endif
                                    </span>
                                </flux:menu.item>
                            </form>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>

                <flux:dropdown position="bottom" align="end">
                    <button type="button" class="crm-user-pill" data-test="sidebar-menu-button">
                        <span class="crm-avatar">{{ auth()->user()->initials() }}</span>
                        <span class="crm-user-copy">
                            <strong>{{ auth()->user()->name }}</strong>
                            <small>{{ auth()->user()->is_super_admin ? __('navigation.super_admin') : __('navigation.internal_user') }}</small>
                        </span>
                        <flux:icon.chevron-down aria-hidden="true" />
                    </button>

                    <flux:menu>
                        <div class="px-2 py-1.5">
                            <div class="text-sm font-semibold">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</div>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>{{ __('navigation.profile') }}</flux:menu.item>
                        <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>{{ __('navigation.security') }}</flux:menu.item>
                        <flux:menu.item :href="route('appearance.edit')" icon="swatch" wire:navigate>{{ __('navigation.appearance') }}</flux:menu.item>
                        <flux:menu.item :href="route('language.edit')" icon="globe-alt" wire:navigate>{{ __('navigation.language') }}</flux:menu.item>
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item
                                as="button"
                                type="submit"
                                icon="arrow-right-start-on-rectangle"
                                class="w-full cursor-pointer"
                                data-test="logout-button"
                            >
                                {{ __('navigation.logout') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </header>

            {{ $slot }}

            @persist('toast')
                <flux:toast.group>
                    <flux:toast />
                </flux:toast.group>
            @endpersist
        </div>

        @fluxScripts
    </body>
</html>
