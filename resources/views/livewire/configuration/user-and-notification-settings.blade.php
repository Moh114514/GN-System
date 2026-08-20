<div>
    <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />

    <section class="crm-section-header">
        <div>
            <p class="text-xs font-medium text-zinc-400">{{ __('config.user_management.eyebrow') }}</p>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-50">{{ __('config.center.cards.users.title') }}</h2>
            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('config.center.cards.users.description') }}</p>
        </div>
        <a href="{{ route('audit-logs.index') }}" class="inline-flex items-center gap-1 text-sm font-semibold text-teal-700 hover:underline dark:text-teal-300" wire:navigate>
            {{ __('config.user_management.audit_link') }}
            <flux:icon.arrow-right class="size-4" aria-hidden="true" />
        </a>
    </section>

    <div class="mb-6 flex flex-wrap gap-2 border-b border-zinc-200 dark:border-zinc-700" role="tablist" aria-label="{{ __('config.center.cards.users.title') }}">
        <button type="button" wire:click="selectTab('users')" role="tab" aria-selected="{{ $activeTab === 'users' ? 'true' : 'false' }}" data-test="users-and-notifications-tab-users" class="border-b-2 px-3 py-2 text-sm font-semibold {{ $activeTab === 'users' ? 'border-teal-600 text-teal-700 dark:border-teal-400 dark:text-teal-300' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' }}">{{ __('config.user_management.title') }}</button>
        <button type="button" wire:click="selectTab('notifications')" role="tab" aria-selected="{{ $activeTab === 'notifications' ? 'true' : 'false' }}" data-test="users-and-notifications-tab-notifications" class="border-b-2 px-3 py-2 text-sm font-semibold {{ $activeTab === 'notifications' ? 'border-teal-600 text-teal-700 dark:border-teal-400 dark:text-teal-300' : 'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' }}">{{ __('config.notification_recipients.title') }}</button>
    </div>

    @if ($activeTab === 'users')
        @livewire(\App\Modules\Config\Presentation\Livewire\UserManagement::class, ['embedded' => true])
    @else
        @livewire(\App\Modules\Config\Presentation\Livewire\NotificationRecipientConfiguration::class, ['embedded' => true])
    @endif
</div>
