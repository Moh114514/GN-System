<?php

namespace App\Modules\Settlement\Application\Contracts;

/**
 * Read-only financial data for an order detail page.
 */
interface OrderFinancialReader
{
    /** @return array{commission_visible: bool, commission: array<string, mixed>|null, settlement: array<string, mixed>|null, correction: array<string, mixed>|null, corrections: array<int, array<string, mixed>>} */
    public function forOrder(int $orderId, int $customerId): array;
}
