<?php

namespace App\Modules\Config\Presentation\Livewire;

use App\Modules\Config\Application\Contracts\NotificationRecipientGateway;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

final class NotificationBell extends Component
{
    public bool $open = false;

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function openNotification(int $id, NotificationRecipientGateway $notifications): void
    {
        $link = $notifications->markNotificationRead($id, (int) Auth::id());
        $target = $this->safeInternalTarget($link) ?? route('reminders.index');
        $this->redirect($target, navigate: true);
    }

    public function render(NotificationRecipientGateway $notifications): View
    {
        $userId = (int) Auth::id();

        return view('livewire.config.notification-bell', [
            'notifications' => $notifications->unreadForUser($userId),
            'unreadCount' => $notifications->unreadCountForUser($userId),
        ]);
    }

    private function safeInternalTarget(?string $link): ?string
    {
        if ($link === null || trim($link) === '') {
            return null;
        }
        $parts = parse_url($link);
        if ($parts === false || isset($parts['scheme']) || isset($parts['user']) || isset($parts['pass'])) {
            if (($parts['host'] ?? null) !== request()->getHost()) {
                return null;
            }
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
