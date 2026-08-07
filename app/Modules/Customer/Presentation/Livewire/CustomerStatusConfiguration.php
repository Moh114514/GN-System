<?php

namespace App\Modules\Customer\Presentation\Livewire;

use App\Modules\Customer\Application\Services\CustomerStatusManager;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class CustomerStatusConfiguration extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $stages = [];

    /** @var array<int, array<string, mixed>> */
    public array $statuses = [];

    public function mount(CustomerStatusManager $manager): void
    {
        $this->loadConfiguration($manager);
    }

    public function save(CustomerStatusManager $manager): void
    {
        $this->validate([
            'stages.*.id' => ['required', 'integer'],
            'stages.*.name' => ['required', 'string', 'max:255'],
            'stages.*.sort_order' => ['required', 'integer', 'min:0'],
            'stages.*.is_active' => ['boolean'],
            'statuses.*.id' => ['required', 'integer'],
            'statuses.*.name' => ['required', 'string', 'max:255'],
            'statuses.*.stage_id' => ['required', 'integer'],
            'statuses.*.sort_order' => ['required', 'integer', 'min:0'],
            'statuses.*.is_active' => ['boolean'],
            'statuses.*.to_status_ids' => ['array'],
            'statuses.*.to_status_ids.*' => ['integer'],
        ]);
        $manager->saveConfiguration($this->stages, $this->statuses, Auth::user(), request()->ip());
        $this->loadConfiguration($manager);
        Flux::toast(variant: 'success', text: __('config.customer_status.toast.saved'));
    }

    public function render(): View
    {
        return view('livewire.customers.customer-status-configuration')->title(__('config.customer_status.title'));
    }

    private function loadConfiguration(CustomerStatusManager $manager): void
    {
        $this->stages = [];
        $this->statuses = [];
        foreach ($manager->configuration() as $stage) {
            $this->stages[] = [
                'id' => $stage['id'],
                'key' => $stage['key'],
                'name' => $stage['name'],
                'sort_order' => $stage['sort_order'],
                'is_active' => $stage['is_active'],
            ];
            foreach ($stage['statuses'] as $status) {
                $this->statuses[] = $status + ['stage_id' => $stage['id']];
            }
        }
    }
}
