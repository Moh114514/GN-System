<?php

namespace App\Modules\Settlement\Application\Data;

final readonly class SettlementRunFailureData
{
    public function __construct(
        public int $agentId,
        public string $agentCode,
        public string $agentName,
        public string $reason,
    ) {}
}
