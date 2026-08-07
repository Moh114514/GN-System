<?php

namespace App\Modules\Settlement\Application\Services;

use App\Modules\Settlement\Application\Contracts\KrwCnyQuoteProvider;
use App\Modules\Settlement\Application\Data\KrwCnyQuoteData;
use App\Modules\Settlement\Infrastructure\Models\Settlement;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final readonly class ExchangeRateQuoteService
{
    public function __construct(private KrwCnyQuoteProvider $provider) {}

    public function refreshFor(Settlement $settlement, bool $force = false): Settlement
    {
        if (! in_array($settlement->status, ['pending_review', 'rejected'], true)) {
            return $settlement;
        }

        $cacheKey = 'settlement:exchange-rate:krw-cny:'.sha1(implode(':', [
            (string) config('services.settlement_exchange_rate.provider'),
            (string) config('services.settlement_exchange_rate.url'),
            (string) config('services.settlement_exchange_rate.id'),
        ]));
        $quote = $force ? null : Cache::get($cacheKey);
        if (! $quote instanceof KrwCnyQuoteData) {
            $quote = $this->provider->quote();
            if ($quote->available) {
                Cache::put($cacheKey, $quote, now()->addMinutes(10));
            }
        }
        DB::transaction(function () use ($settlement, $quote): void {
            $locked = Settlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if (! in_array($locked->status, ['pending_review', 'rejected'], true)) {
                return;
            }

            $attemptedAt = now();
            if ($quote->available) {
                $locked->update([
                    'exchange_rate_krw_per_cny' => $quote->rate,
                    'exchange_rate_quote_source' => $quote->source,
                    'exchange_rate_quoted_at' => $quote->quotedAt,
                    'exchange_rate_quote_attempted_at' => $attemptedAt,
                    'exchange_rate_quote_status' => 'available',
                    'exchange_rate_quote_error' => null,
                    'exchange_rate_quote_error_key' => null,
                    'exchange_rate_quote_error_parameters' => null,
                    'exchange_rate_manual_override' => false,
                ]);

                return;
            }

            $attributes = [
                'exchange_rate_quote_error' => (string) str($quote->failureReason)->limit(500, ''),
                'exchange_rate_quote_error_key' => 'settlements.quote_failures.unavailable',
                'exchange_rate_quote_error_parameters' => [],
                'exchange_rate_quote_attempted_at' => $attemptedAt,
                'exchange_rate_quote_status' => $locked->exchange_rate_krw_per_cny === null
                    ? 'unavailable'
                    : 'failed_retained_old_rate',
            ];
            if ($locked->exchange_rate_krw_per_cny === null) {
                $attributes += [
                    'exchange_rate_quote_source' => $quote->source,
                    'exchange_rate_quoted_at' => null,
                    'exchange_rate_manual_override' => false,
                ];
            }
            $locked->update($attributes);
        });

        $settlement->refresh();

        return $settlement;
    }
}
