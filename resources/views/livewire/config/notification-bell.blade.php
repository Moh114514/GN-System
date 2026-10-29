<div class="relative">
    <a
        href="{{ route('reminders.index') }}"
        class="crm-icon-button crm-notification-button relative"
        data-test="reminder-notification-button"
        aria-label="{{ __('navigation.view_reminders') }}"
        aria-expanded="{{ $open ? 'true' : 'false' }}"
        wire:click.prevent="toggle"
    >
        <flux:icon.bell aria-hidden="true" />
        @if ($unreadCount > 0)
            <span data-test="notification-unread-count" class="absolute -right-1 -top-1 min-w-5 rounded-full bg-red-600 px-1 text-center text-xs font-semibold leading-5 text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </a>
    @if ($open)
        <section data-test="notification-panel" class="absolute right-0 top-12 z-50 w-80 max-w-[calc(100vw-2rem)] rounded-xl border border-zinc-200 bg-white p-2 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="px-3 py-2 text-sm font-semibold">{{ __('navigation.unread_notifications', ['count' => $unreadCount]) }}</h2>
            <div class="max-h-96 overflow-y-auto">
                @forelse ($notifications as $notification)
                    <button type="button" wire:click="openNotification({{ $notification['id'] }})" class="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800" data-test="notification-item">
                        <span class="block text-sm font-medium">{{ $notification['title'] }}</span>
                        <span class="mt-1 block line-clamp-3 whitespace-pre-line text-xs text-zinc-500">{{ $notification['body'] }}</span>
                        <time class="mt-1 block text-[11px] text-zinc-400">{{ $notification['created_at'] ? \Carbon\CarbonImmutable::parse($notification['created_at'])->format('Y-m-d H:i') : '' }}</time>
                    </button>
                @empty
                    <p class="px-3 py-5 text-center text-sm text-zinc-500">{{ __('navigation.no_unread_notifications') }}</p>
                @endforelse
            </div>
            <a href="{{ route('reminders.index') }}" class="mt-1 block rounded-lg px-3 py-2 text-center text-sm text-teal-700 hover:bg-zinc-100 dark:text-teal-300 dark:hover:bg-zinc-800" wire:navigate>{{ __('navigation.view_reminders') }}</a>
        </section>
    @endif
</div>
