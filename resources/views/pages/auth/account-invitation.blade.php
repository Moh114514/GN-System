<x-layouts::auth :title="__('auth.invitation.title')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('auth.invitation.title')" :description="__('auth.invitation.description')" />

        <form method="POST" action="{{ route('account.invitation.store', ['token' => $token, 'email' => $email]) }}" class="flex flex-col gap-6">
            @csrf
            <input type="hidden" name="email" value="{{ $email }}">

            <flux:input
                name="password"
                :label="__('auth.invitation.password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('auth.invitation.password_confirmation')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('auth.invitation.submit') }}
            </flux:button>
        </form>
    </div>
</x-layouts::auth>
