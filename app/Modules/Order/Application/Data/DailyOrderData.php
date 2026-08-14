<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class DailyOrderData
{
    public function __construct(
        public int $customerId,
        public int $institutionId,
        public int $agentId,
        public string $projectName,
        public int $amountKrw,
        public string $status,
        public ?CarbonImmutable $completedOn,
        public ?string $translatorName,
        public ?string $notes,
        public int $ownerId,
        public ?string $ipAddress,
        public ?int $treatmentProjectId = null,
        public ?int $translatorLanguageId = null,
        public ?string $translatorLanguageName = null,
    ) {}
}
