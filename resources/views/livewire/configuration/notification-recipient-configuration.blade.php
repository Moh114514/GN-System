<div>
    @unless ($embedded)
        <x-page-back :href="route('configuration.index')" :label="__('config.back_to_configuration')" class="mb-4" />
    @endunless
    @unless ($embedded)
    <section class="crm-section-header">
        <div><p class="text-xs font-medium text-zinc-400">{{ __('config.notification_recipients.eyebrow') }}</p><h2 class="mt-1 text-2xl font-bold">{{ __('config.notification_recipients.title') }}</h2><p class="mt-2 text-sm text-zinc-500">{{ __('config.notification_recipients.description') }}</p></div>
    </section>
    @endunless
    <form wire:submit="save" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <fieldset><legend class="font-semibold">{{ __('config.notification_recipients.internal_heading') }}</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">@foreach ($users as $user)<label class="flex items-center gap-2"><input type="checkbox" wire:model="internalUserIds" value="{{ $user->id }}"><span>{{ $user->name }}</span></label>@endforeach</div></fieldset>
        <fieldset class="mt-6"><legend class="font-semibold">{{ __('config.notification_recipients.dingtalk_heading') }}</legend><div class="mt-3 grid gap-2 sm:grid-cols-2">@foreach ($users as $user)@php($dingtalkMentionIsBound = in_array($user->dingtalk_mention_type, ['user_id', 'mobile'], true) && filled($user->dingtalk_mention_value))<label class="flex items-center gap-2 {{ $dingtalkMentionIsBound ? '' : 'cursor-not-allowed opacity-60' }}"><input type="checkbox" wire:model="dingtalkUserIds" value="{{ $user->id }}" @disabled(! $dingtalkMentionIsBound)><span>{{ $user->name }} ({{ $dingtalkMentionIsBound ? __('config.notification_recipients.dingtalk_bound') : __('config.notification_recipients.dingtalk_missing') }})</span></label>@endforeach</div>@error('dingtalkUserIds')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror</fieldset>
        <div class="mt-6"><flux:button type="submit" variant="primary">{{ __('config.notification_recipients.save') }}</flux:button></div>
    </form>
</div>
