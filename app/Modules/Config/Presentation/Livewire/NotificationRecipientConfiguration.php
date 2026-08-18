<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Models\User;
use App\Modules\Config\Infrastructure\Models\NotificationRecipientConfig;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class NotificationRecipientConfiguration extends Component
{
    /** @var list<int> */
    public array $internalUserIds = [];

    /** @var list<int> */
    public array $dingtalkUserIds = [];

    public function mount(): void
    {
        $this->loadSelections();
    }

    public function save(): void
    {
        $this->validate([
            'internalUserIds' => ['array'],
            'internalUserIds.*' => ['integer', 'exists:users,id'],
            'dingtalkUserIds' => ['array'],
            'dingtalkUserIds.*' => ['integer', 'exists:users,id'],
        ]);
        DB::transaction(function (): void {
            foreach (['internal' => $this->internalUserIds, 'dingtalk' => $this->dingtalkUserIds] as $channel => $ids) {
                NotificationRecipientConfig::query()
                    ->where('event_type', 'agent_grade_adjustment')
                    ->where('channel', $channel)
                    ->delete();
                foreach (array_unique(array_map('intval', $ids)) as $userId) {
                    NotificationRecipientConfig::query()->create([
                        'event_type' => 'agent_grade_adjustment',
                        'user_id' => $userId,
                        'channel' => $channel,
                        'enabled' => true,
                    ]);
                }
            }
        });
        Flux::toast(variant: 'success', text: __('config.notification_recipients.toast.saved'));
    }

    public function render(): View
    {
        return view('livewire.configuration.notification-recipient-configuration', [
            'users' => User::query()->where('is_active', true)->orderBy('name')->get(),
        ])->title(__('config.notification_recipients.title'));
    }

    private function loadSelections(): void
    {
        $records = NotificationRecipientConfig::query()->where('event_type', 'agent_grade_adjustment')->get();
        $this->internalUserIds = $records->where('channel', 'internal')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->dingtalkUserIds = $records->where('channel', 'dingtalk')->pluck('user_id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
