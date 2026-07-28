<?php

namespace App\Modules\Agent\Application\Data;

final readonly class SettlementAgentData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public int $policySystemId,
        public int $currentGradeId,
        public string $currentGradeName,
    ) {}
}
