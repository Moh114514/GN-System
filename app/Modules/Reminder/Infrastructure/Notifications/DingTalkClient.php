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

    public function send(string $title, string $text, ?string $link = null): void
    {
        if (! $this->enabled()) {
            throw new DomainException('钉钉通知未启用或 Webhook 未配置。');
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
            $content .= "\n\n[打开 GN-System]({$link})";
        }
        $response = Http::timeout(10)->post($url, [
            'msgtype' => 'markdown',
            'markdown' => ['title' => $title, 'text' => $content],
        ]);
        $response->throw();
        if ((int) $response->json('errcode', 0) !== 0) {
            throw new DomainException('钉钉机器人拒绝消息：'.(string) $response->json('errmsg', '未知错误'));
        }
    }
}
