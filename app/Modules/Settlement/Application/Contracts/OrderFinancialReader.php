<?php

namespace App\Modules\Settlement\Application\Contracts;

/**
 * Read-only financial data for an order detail page.
 */
interface OrderFinancialReader
{
    /** @return array{commission: array<string, mixed>|null, settlement: array<string, mixed>|null} */
    public function forOrder(int $orderId): array;
}
