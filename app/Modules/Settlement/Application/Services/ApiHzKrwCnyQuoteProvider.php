<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\KrwCnyQuoteProvider;
use App\Modules\Settlement\Application\Data\KrwCnyQuoteData;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 接口盒子汇率服务（阿里云开发者文章推荐的接口）。
 *
 * The service publishes a daily-updated quote. It is therefore an automatic
 * prefill source, while the settlement reviewer remains able to override it.
 */
final class ApiHzKrwCnyQuoteProvider implements KrwCnyQuoteProvider
{
    public function quote(): KrwCnyQuoteData
    {
        $source = 'api_hz';
        if (! (bool) config('services.settlement_exchange_rate.enabled', true)) {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价已被配置禁用。');
        }

        $id = trim((string) config('services.settlement_exchange_rate.id', ''));
        $key = trim((string) config('services.settlement_exchange_rate.key', ''));
        if ($id === '' || $key === '') {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价服务缺少 ID 或 Key 配置。');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('services.settlement_exchange_rate.timeout', 10))
                ->get((string) config('services.settlement_exchange_rate.url'), [
                    'id' => $id,
                    'key' => $key,
                    'from' => 'CNY',
                    'to' => 'KRW',
                    'money' => '1',
                ]);
        } catch (Throwable) {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价服务暂时不可用，请人工填写结算汇率。');
        }

        if (! $response->successful()) {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价服务返回 HTTP '.$response->status().'，请人工填写结算汇率。');
        }

        if ((int) $response->json('code') !== 200) {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价服务未返回有效汇率，请人工填写结算汇率。');
        }

        try {
            $rate = BigDecimal::of((string) $response->json('rate'))
                ->toScale(6, RoundingMode::HalfUp);
            if ($rate->isLessThanOrEqualTo(0)) {
                throw new DomainException(__('settlements.errors.quote_must_be_positive'));
            }
        } catch (MathException|DomainException) {
            return new KrwCnyQuoteData(false, $source, failureReason: '自动报价服务未返回有效的 KRW/CNY 汇率，请人工填写结算汇率。');
        }

        $quotedAt = $this->parseQuotedAt($response->json('uptime'));

        return new KrwCnyQuoteData(true, $source, (string) $rate, $quotedAt);
    }

    private function parseQuotedAt(mixed $uptime): CarbonImmutable
    {
        if (is_string($uptime) && trim($uptime) !== '') {
            try {
                return CarbonImmutable::parse($uptime);
            } catch (Throwable) {
                // Keep the quote usable when the provider sends an unexpected timestamp.
            }
        }

        return CarbonImmutable::now();
    }
}
