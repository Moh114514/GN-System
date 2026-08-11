<x-layouts::auth :title="__('auth.invitation.success_title')">
    <div class="flex flex-col gap-6 text-center">
        <x-auth-header :title="__('auth.invitation.success_title')" :description="__('auth.invitation.success_description')" />
        <p>{{ __('auth.invitation.success_account', ['email' => $email]) }}</p>
        @if (auth()->check())
            <p class="text-sm text-zinc-500">{{ __('auth.invitation.success_current_session', ['email' => auth()->user()->email]) }}</p>
        @endif
        <flux:link :href="route('login')" wire:navigate>{{ __('auth.invitation.login') }}</flux:link>
    </div>
</x-layouts::auth>
