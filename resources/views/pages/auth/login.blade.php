<x-layouts::auth :title="__('Log in')">
    <div class="crm-login">
        <header>
            <span class="crm-login-eyebrow">CRM 管理系统</span>
            <h1>欢迎回来</h1>
            <p>请输入您的账户信息以继续访问工作台</p>
        </header>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="crm-login-form">
            @csrf

            <flux:input
                name="email"
                label="邮箱地址"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="name@company.com"
                icon="envelope"
            />

            <div class="relative">
                <flux:input
                    name="password"
                    label="密码"
                    type="password"
                    required
                    autocomplete="current-password"
                    placeholder="请输入密码"
                    icon="lock-closed"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        忘记密码？
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" label="记住我" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="crm-login-button" data-test="login-button">
                登录系统
            </flux:button>
        </form>

        <p class="crm-login-help">仅限已授权的内部员工使用</p>
    </div>
</x-layouts::auth>
