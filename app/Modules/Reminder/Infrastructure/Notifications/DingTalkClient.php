<?php

namespace App\Modules\Reminder\Infrastructure\Notifications;

use App\Modules\Reminder\Application\Contracts\StaffNotificationSender;
use DomainException;
use Illuminate\Support\Facades\Http;

final class DingTalkClient implements StaffNotificationSender
{
    public function enabled(): bool
    {
        return (bool) config('dingtalk.enabled')
            && is_string(config('dingtalk.webhook_url'))
            && config('dingtalk.webhook_url') !== '';
    }

    /** @param list<string> $recipients */
    public function send(string $title, string $text, ?string $link = null, array $recipients = []): void
    {
        if (! $this->enabled()) {
            throw new DomainException(__('reminders.errors.dingtalk_not_configured'));
        }
        $normalizedRecipients = [];
        foreach ($recipients as $recipient) {
            $recipient = trim((string) $recipient);
            if ($recipient === '') {
                throw new DomainException(__('auth.errors.dingtalk_user_id_required'));
            }
            if (mb_strlen($recipient) > 255) {
                throw new DomainException(__('auth.errors.dingtalk_user_id_too_long'));
            }

            $normalizedRecipients[] = $recipient;
        }
        $recipients = $normalizedRecipients;
        $url = (string) config('dingtalk.webhook_url');
        $secret = (string) config('dingtalk.secret');
        if ($secret !== '') {
            $timestamp = (string) ((int) floor(microtime(true) * 1000));
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.urlencode($sign);
        }
        $content = "### {$title}\n\n{$text}";
        if ($recipients !== []) {
            $content .= "\n\n".implode(' ', array_map(static fn (string $recipient): string => '@'.$recipient, $recipients));
        }
        if ($link !== null) {
            $content .= "\n\n[".__('common.open_system')."]({$link})";
        }
        $response = Http::timeout(10)->post($url, [
            'msgtype' => 'markdown',
            'markdown' => ['title' => $title, 'text' => $content],
            'at' => ['atUserIds' => $recipients, 'isAtAll' => false],
        ]);
        $response->throw();
        if ((int) $response->json('errcode', 0) !== 0) {
            throw new DomainException(__('reminders.errors.dingtalk_rejected', [
                'reason' => (string) $response->json('errmsg', __('reminders.errors.unknown_remote_error')),
            ]));
        }
    }
}
