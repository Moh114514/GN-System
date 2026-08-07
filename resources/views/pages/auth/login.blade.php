<x-layouts::auth :title="__('Log in')">
    <div class="crm-login">
        <header>
            <span class="crm-login-eyebrow">{{ __('auth.login.eyebrow') }}</span>
            <h1>{{ __('auth.login.welcome') }}</h1>
            <p>{{ __('auth.login.description') }}</p>
        </header>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="crm-login-form">
            @csrf

            <flux:input
                name="email"
                :label="__('auth.login.email')"
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
                    :label="__('auth.login.password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('auth.login.password_placeholder')"
                    icon="lock-closed"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('auth.login.forgot_password') }}
                    </flux:link>
                @endif
            </div>

            <flux:checkbox name="remember" :label="__('auth.login.remember')" :checked="old('remember')" />

            <flux:button variant="primary" type="submit" class="crm-login-button" data-test="login-button">
                {{ __('auth.login.submit') }}
            </flux:button>
        </form>

        <p class="crm-login-help">{{ __('auth.login.help') }}</p>
    </div>
</x-layouts::auth>
