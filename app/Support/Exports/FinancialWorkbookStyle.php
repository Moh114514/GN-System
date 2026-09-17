<?php

namespace App\Support\Exports;

final class FinancialWorkbookStyle
{
    public const ACCENT = '7A2929';

    public const ACCENT_DARK = '5B1F1F';

    public const PALE_CYAN = 'EAF6F6';

    public const BORDER = 'D9E0E0';

    public const TEXT = '242424';

    public const MUTED = '667070';

    public static function currencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'CNY', 'RMB' => '¥',
            'KRW' => '₩',
            'USD' => '$',
            default => strtoupper($currency),
        };
    }

    public static function decimals(string $currency, ?int $decimals = null): int
    {
        return $decimals ?? (strtoupper($currency) === 'KRW' ? 0 : 2);
    }

    public static function numberFormat(string $currency, ?int $decimals = null): string
    {
        return '#,##0'.(self::decimals($currency, $decimals) > 0 ? '.'.str_repeat('0', self::decimals($currency, $decimals)) : '');
    }
}
