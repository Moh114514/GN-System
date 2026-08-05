<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="crm-body" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        <aside
            class="crm-sidebar"
            :class="{ 'is-open': sidebarOpen }"
            aria-label="主导航"
        >
            <a href="{{ route('dashboard') }}" class="crm-brand" wire:navigate>
                <img
                    class="crm-brand-logo"
                    src="{{ asset('images/lightyear18-logo.png') }}"
                    alt="光年拾捌 Lightyear 18"
                >
                <span>
                    <strong>GN-System</strong>
                    <small>专业 · 安全 · 高效</small>
                </span>
            </a>

            <nav class="crm-nav">
                <a href="{{ route('dashboard') }}" class="crm-nav-item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.home aria-hidden="true" />
                    <span>总览</span>
                </a>

                @if (auth()->user()->is_super_admin)
                    <a href="{{ route('data-imports.index') }}" class="crm-nav-item {{ request()->routeIs('data-imports.*') ? 'is-active' : '' }}" wire:navigate>
                        <flux:icon.arrow-up-tray aria-hidden="true" />
                        <span>数据迁移</span>
                    </a>
                    <a href="{{ route('agents.index') }}" class="crm-nav-item {{ request()->routeIs('agents.*') ? 'is-active' : '' }}" wire:navigate>
                        <flux:icon.building-office aria-hidden="true" />
                        <span>代理商</span>
                    </a>
                    @php
                        $configurationNavigationActive = request()->routeIs(
                            'configuration.*',
                            'agent-configuration.*',
                            'customer-statuses.*',
                            'direct-sales-sources.*',
                            'reminder-configuration.*',
                            'reference-configuration-imports.*',
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
                                <span>配置中心</span>
                            </a>

                            <button
                                type="button"
                                class="crm-nav-group-toggle"
                                @click="open = !open"
                                :aria-expanded="open"
                                aria-controls="configuration-subnav"
                                aria-label="展开或收起配置中心"
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
                            class="crm-subnav-collapse"
                            x-bind:class="{ 'is-open': open }"
                            x-bind:aria-hidden="(!open).toString()"
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
                                        配置总览
                                    </a>

                                    <a
                                        href="{{ route('configuration.catalog') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.catalog') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-catalog"
                                        wire:navigate
                                    >
                                        机构与字典
                                    </a>

                                    <a
                                        href="{{ route('direct-sales-sources.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('direct-sales-sources.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-direct-sales-sources"
                                        wire:navigate
                                    >
                                        直销来源
                                    </a>

                                    <a
                                        href="{{ route('customer-statuses.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('customer-statuses.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-customer-statuses"
                                        wire:navigate
                                    >
                                        客户状态
                                    </a>

                                    <a
                                        href="{{ route('agent-configuration.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('agent-configuration.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-agent"
                                        wire:navigate
                                    >
                                        代理商与推广费
                                    </a>

                                    <a
                                        href="{{ route('reminder-configuration.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('reminder-configuration.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-reminder"
                                        wire:navigate
                                    >
                                        提醒规则
                                    </a>

                                    <a
                                        href="{{ route('configuration.users') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.users') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-users"
                                        wire:navigate
                                    >
                                        用户与权限
                                    </a>

                                    <a
                                        href="{{ route('configuration.history') }}"
                                        class="crm-subnav-item {{ request()->routeIs('configuration.history') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-history"
                                        wire:navigate
                                    >
                                        配置历史
                                    </a>

                                    <a
                                        href="{{ route('reference-configuration-imports.index') }}"
                                        class="crm-subnav-item {{ request()->routeIs('reference-configuration-imports.*') ? 'is-active' : '' }}"
                                        data-test="configuration-subnav-reference-imports"
                                        wire:navigate
                                    >
                                        基础配置导入
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                <a href="{{ route('customers.index') }}" class="crm-nav-item {{ request()->routeIs('customers.index', 'customers.create', 'customers.show', 'customers.edit') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.users aria-hidden="true" />
                    <span>客户管理</span>
                </a>

                <a href="{{ route('reports.search') }}" class="crm-nav-item {{ request()->routeIs('reports.search', 'reports.exports.*') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.magnifying-glass aria-hidden="true" />
                    <span>多维查询</span>
                </a>

                @if (auth()->user()->is_super_admin)
                    <a href="{{ route('settlements.index') }}" class="crm-nav-item {{ request()->routeIs('settlements.*') ? 'is-active' : '' }}" wire:navigate>
                        <flux:icon.banknotes aria-hidden="true" />
                        <span>月结中心</span>
                    </a>
                @endif

                <a href="{{ route('reminders.index') }}" class="crm-nav-item {{ request()->routeIs('reminders.*') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.bell-alert aria-hidden="true" />
                    <span>主动提醒</span>
                </a>

                <a href="{{ route('orders.index') }}" class="crm-nav-item {{ request()->routeIs('orders.*', 'customers.orders') ? 'is-active' : '' }}" wire:navigate>
                    <flux:icon.clipboard-document-list aria-hidden="true" />
                    <span>订单</span>
                </a>
            </nav>

            <div class="crm-sidebar-footer">
                <a href="{{ route('security.edit') }}" class="crm-security-card" wire:navigate>
                    <span class="crm-security-title">
                        <flux:icon.shield-check aria-hidden="true" />
                        数据安全
                    </span>
                    <span>账户保护与登录安全</span>
                    <strong>查看安全设置 <span aria-hidden="true">›</span></strong>
                </a>
            </div>
        </aside>

        <button
            type="button"
            class="crm-scrim"
            :class="{ 'is-visible': sidebarOpen }"
            @click="sidebarOpen = false"
            aria-label="关闭导航"
        ></button>

        <div class="crm-shell">
            <header class="crm-topbar">
                <button
                    type="button"
                    class="crm-icon-button crm-menu-button"
                    @click="sidebarOpen = true"
                    aria-label="打开导航"
                >
                    <flux:icon.bars-3 />
                </button>

                <h1>{{ $title ?? 'CRM 管理系统' }}</h1>
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
                        <label class="sr-only" for="global-search">全局搜索</label>
                        <input
                            id="global-search"
                            x-ref="input"
                            x-model="query"
                            @focus="open = true"
                            @input="open = true"
                            name="q"
                            type="search"
                            placeholder="搜索客户、订单、代理商、手机号等"
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
                            <span>在客户中搜索<strong x-text="query ? `“${query}”` : ''"></strong></span>
                        </a>
                        <a
                            x-bind:href="'{{ route('reports.search') }}' + (query ? '?projectName=' + encodeURIComponent(query) : '')"
                            @click="open = false"
                            role="menuitem"
                        >
                            <flux:icon.clipboard-document-list aria-hidden="true" />
                            <span>在订单项目中搜索<strong x-text="query ? `“${query}”` : ''"></strong></span>
                        </a>
                        @if (auth()->user()->is_super_admin)
                            <a
                                x-bind:href="'{{ route('agents.index') }}?search=' + encodeURIComponent(query)"
                                @click="open = false"
                                role="menuitem"
                            >
                                <flux:icon.building-office aria-hidden="true" />
                                <span>在代理商中搜索<strong x-text="query ? `“${query}”` : ''"></strong></span>
                            </a>
                        @endif
                    </div>
                </div>

                <form action="{{ route('dashboard') }}" method="GET" class="crm-date-form">
                    <label class="crm-date-range">
                        <span class="sr-only">查看指定日期的看板</span>
                        <input
                            data-test="topbar-date-control"
                            type="date"
                            name="date"
                            value="{{ request()->routeIs('dashboard') ? (string) request('date', now('Asia/Shanghai')->format('Y-m-d')) : now('Asia/Shanghai')->format('Y-m-d') }}"
                            aria-label="查看指定日期的看板"
                            onchange="this.form.requestSubmit()"
                        >
                    </label>
                </form>

                <a
                    href="{{ route('reminders.index') }}"
                    class="crm-icon-button crm-notification-button"
                    data-test="reminder-notification-button"
                    aria-label="查看主动提醒"
                    wire:navigate
                >
                    <flux:icon.bell aria-hidden="true" />
                </a>

                <flux:dropdown position="bottom" align="end">
                    <button type="button" class="crm-user-pill" data-test="sidebar-menu-button">
                        <span class="crm-avatar">{{ auth()->user()->initials() }}</span>
                        <span class="crm-user-copy">
                            <strong>{{ auth()->user()->name }}</strong>
                            <small>{{ auth()->user()->is_super_admin ? '超级管理员' : '内部用户' }}</small>
                        </span>
                        <flux:icon.chevron-down aria-hidden="true" />
                    </button>

                    <flux:menu>
                        <div class="px-2 py-1.5">
                            <div class="text-sm font-semibold">{{ auth()->user()->name }}</div>
                            <div class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</div>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('profile.edit')" icon="user-circle" wire:navigate>账户设置</flux:menu.item>
                        <flux:menu.item :href="route('security.edit')" icon="shield-check" wire:navigate>安全设置</flux:menu.item>
                        <flux:menu.item :href="route('appearance.edit')" icon="swatch" wire:navigate>外观设置</flux:menu.item>
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
                                退出登录
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
