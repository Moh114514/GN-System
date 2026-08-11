<x-layouts::auth :title="__('auth.password_reset.title')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('auth.password_reset.title')" :description="__('auth.password_reset.description')" />

        <form method="POST" action="{{ route('account.password-reset.store', ['token' => $token, 'email' => $email]) }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">

            <flux:input
                name="password"
                :label="__('auth.password_reset.password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('auth.password_reset.password_confirmation')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.password_reset.submit') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
