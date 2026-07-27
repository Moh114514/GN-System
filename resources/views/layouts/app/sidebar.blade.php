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
                <span class="crm-brand-mark" aria-hidden="true">G</span>
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

                @foreach ([
                    ['users', '客户管理'],
                    ['building-office', '代理商'],
                    ['clipboard-document-list', '订单'],
                    ['banknotes', '月结中心'],
                    ['bell-alert', '主动提醒'],
                    ['magnifying-glass', '多维查询'],
                    ['chart-bar', '数据看板'],
                    ['cog-6-tooth', '配置中心'],
                ] as [$icon, $label])
                    <span class="crm-nav-item is-disabled" aria-disabled="true" title="功能将在后续阶段开放">
                        <flux:icon :name="$icon" aria-hidden="true" />
                        <span>{{ $label }}</span>
                        <span class="crm-nav-lock">待开放</span>
                    </span>
                @endforeach
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

                <label class="crm-search">
                    <flux:icon.magnifying-glass aria-hidden="true" />
                    <span class="sr-only">全局搜索</span>
                    <input type="search" placeholder="搜索客户、订单、代理商、手机号等" disabled>
                    <kbd>⌘ K</kbd>
                </label>

                <button type="button" class="crm-date-range" disabled>
                    <span>2025-05-01</span>
                    <span>—</span>
                    <span>2025-05-31</span>
                    <flux:icon.calendar-days aria-hidden="true" />
                </button>

                <button type="button" class="crm-icon-button crm-notification-button" aria-label="通知，11 条未读" disabled>
                    <flux:icon.bell aria-hidden="true" />
                    <span>11</span>
                </button>

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
