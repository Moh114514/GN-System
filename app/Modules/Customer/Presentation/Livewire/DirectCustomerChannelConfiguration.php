<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\DirectCustomerChannelManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DirectCustomerChannelConfiguration extends Component
{
    #[Locked]
    public ?int $channelId = null;

    public string $code = '';

    public string $name = '';

    public string $sortOrder = '0';

    /** @var list<array<string, mixed>> */
    public array $channels = [];

    public function mount(DirectCustomerChannelManager $manager): void
    {
        $this->channels = $manager->channels();
    }

    public function edit(int $id, DirectCustomerChannelManager $manager): void
    {
        $channel = $manager->channel($id);
        $this->resetValidation();
        $this->channelId = (int) $channel['id'];
        $this->code = (string) $channel['code'];
        $this->name = (string) $channel['name'];
        $this->sortOrder = (string) $channel['sort_order'];
    }

    public function save(DirectCustomerChannelManager $manager): void
    {
        $this->validate(['sortOrder' => ['required', 'integer', 'between:0,32767']]);
        $manager->save($this->channelId, $this->code, $this->name, (int) $this->sortOrder, request()->ip());
        $this->cancel();
        $this->channels = $manager->channels();
        Flux::toast(variant: 'success', text: __('config.direct_customer.channels.saved'));
    }

    public function toggle(int $id, DirectCustomerChannelManager $manager): void
    {
        $manager->toggle($id, request()->ip());
        $this->channels = $manager->channels();
        Flux::toast(variant: 'success', text: __('config.direct_customer.channels.status_changed'));
    }

    public function cancel(): void
    {
        $this->reset('channelId', 'code', 'name', 'sortOrder');
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.customers.direct-customer-channel-configuration');
    }
}
