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

    public function send(string $title, string $text, ?string $link = null, array $recipients = []): void
    {
        if (! $this->enabled()) {
            throw new DomainException(__('reminders.errors.dingtalk_not_configured'));
        }
        $atUserIds = [];
        $atMobiles = [];
        /** @var list<mixed> $runtimeRecipients */
        $runtimeRecipients = $recipients;
        foreach ($runtimeRecipients as $recipient) {
            $type = is_array($recipient) ? ($recipient['type'] ?? null) : null;
            if (! is_string($type) || ! in_array($type, ['user_id', 'mobile'], true)) {
                throw new DomainException(__('auth.errors.dingtalk_mention_type_invalid'));
            }

            $value = $recipient['value'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new DomainException(__('auth.errors.dingtalk_mention_value_required'));
            }
            $value = trim($value);
            if (mb_strlen($value) > 255) {
                throw new DomainException(__('auth.errors.dingtalk_mention_value_too_long'));
            }

            if ($type === 'user_id') {
                $atUserIds[] = $value;
            } else {
                $atMobiles[] = $value;
            }
        }
        $url = (string) config('dingtalk.webhook_url');
        $secret = (string) config('dingtalk.secret');
        if ($secret !== '') {
            $timestamp = (string) ((int) floor(microtime(true) * 1000));
            $sign = base64_encode(hash_hmac('sha256', $timestamp."\n".$secret, $secret, true));
            $url .= (str_contains($url, '?') ? '&' : '?').'timestamp='.$timestamp.'&sign='.urlencode($sign);
        }
        $content = "### {$title}\n\n{$text}";
        if ($link !== null) {
            $content .= "\n\n[".__('common.open_system')."]({$link})";
        }
        $at = [];
        if ($atMobiles !== []) {
            $at['atMobiles'] = $atMobiles;
        }
        if ($atUserIds !== []) {
            $at['atUserIds'] = $atUserIds;
        }
        $at['isAtAll'] = false;
        $response = Http::timeout(10)->post($url, [
            'msgtype' => 'markdown',
            'markdown' => ['title' => $title, 'text' => $content],
            'at' => $at,
        ]);
        $response->throw();
        if ((int) $response->json('errcode', 0) !== 0) {
            throw new DomainException(__('reminders.errors.dingtalk_rejected', [
                'reason' => (string) $response->json('errmsg', __('reminders.errors.unknown_remote_error')),
            ]));
        }
    }
}
