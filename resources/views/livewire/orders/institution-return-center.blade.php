<div class="mx-auto max-w-6xl space-y-6">
    <x-page-back :href="route('orders.index')" :label="__('orders.back.order_center')" />

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-teal-600">{{ __('orders.institution_return.eyebrow') }}</p>
                <h1 class="mt-2 text-2xl font-semibold">{{ __('orders.institution_return.title') }}</h1>
                <p class="mt-2 max-w-3xl text-sm text-zinc-500">{{ __('orders.institution_return.description') }}</p>
            </div>
            <span class="crm-pill tone-blue">{{ __('orders.institution_return.version') }}</span>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-5 md:grid-cols-2">
            <flux:select wire:model="institutionId" :label="__('orders.fields.institution')" required>
                <flux:select.option value="">{{ __('orders.fields.select') }}</flux:select.option>
                @foreach ($institutions as $institution)
                    <flux:select.option value="{{ $institution['id'] }}">{{ $institution['code'] }} · {{ $institution['name'] }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:input wire:model.live.debounce.300ms="customerSearch" :label="__('orders.institution_return.customer')" :placeholder="__('orders.center.customer_placeholder')" />
                @if ($selectedCustomer)
                    <div class="mt-2 flex items-center justify-between rounded-xl bg-teal-50 px-3 py-2 text-sm dark:bg-teal-950/30">
                        <span>{{ $selectedCustomer['code'] }} · {{ $selectedCustomer['name'] }}</span>
                        <flux:button wire:click="clearCustomer" variant="ghost" size="sm">{{ __('orders.center.reselect_customer') }}</flux:button>
                    </div>
                @elseif ($customerCandidates !== [])
                    <div class="mt-2 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @foreach ($customerCandidates as $customer)
                            <button type="button" wire:click="selectCustomer({{ $customer['id'] }})" class="block w-full px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="font-medium">{{ $customer['name'] }}</span>
                                <span class="ml-2 text-xs text-zinc-500">{{ $customer['code'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
                @error('customerId') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <flux:button wire:click="downloadTemplate" variant="ghost" icon="arrow-down-tray">{{ __('orders.institution_return.download_template') }}</flux:button>
            <span class="text-xs text-zinc-500">{{ __('orders.institution_return.download_hint') }}</span>
        </div>
    </section>

    <form wire:submit="uploadReturn" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="text-lg font-semibold">{{ __('orders.institution_return.upload_title') }}</h2>
        <p class="mt-1 text-sm text-zinc-500">{{ __('orders.institution_return.upload_description') }}</p>
        <div class="mt-5 max-w-xl">
            <flux:input type="file" wire:model="upload" accept=".xlsx,.xlsm,.xls" :label="__('orders.institution_return.file')" required />
            @error('upload') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>
        <div class="mt-5 flex justify-end">
            <flux:button type="submit" variant="primary">{{ __('orders.institution_return.submit') }}</flux:button>
        </div>
    </form>

    <p class="text-xs text-zinc-500">{{ __('orders.institution_return.security_hint') }}</p>
</div>
