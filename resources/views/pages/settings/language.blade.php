<x-layouts::app :title="__('language.title')">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('language.title')" :subheading="__('language.description')">
        <form method="POST" action="{{ route('locale.update') }}" class="my-6 w-full space-y-6">
            @csrf

            <fieldset class="space-y-3">
                <legend class="sr-only">{{ __('language.title') }}</legend>

                @foreach (config('localization.supported', []) as $locale => $label)
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border p-3">
                        <input
                            type="radio"
                            name="locale"
                            value="{{ $locale }}"
                            @checked(app()->getLocale() === $locale)
                        >
                        <span>{{ __('language.options.'.$locale, [], $locale) }}</span>
                    </label>
                @endforeach
            </fieldset>

            @error('locale')
                <flux:text class="text-red-600">{{ $message }}</flux:text>
            @enderror

            <flux:button variant="primary" type="submit">
                {{ __('language.save') }}
            </flux:button>
        </form>
    </x-pages::settings.layout>
</x-layouts::app>
