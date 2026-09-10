<?php

namespace App\Modules\Order\Application\Data;

use Carbon\CarbonImmutable;

final readonly class CompletedOrderRegistrationData
{
    /**
     * @param  array<int, CompletedOrderItemData>  $items
     * @param  array<int, OrderEvidenceUploadData>  $evidence
     * @param  array<string, mixed>  $sourceMetadata
     */
    public function __construct(
        public int $customerId,
        public int $institutionId,
        public int $agentId,
        public array $items,
        public CarbonImmutable $occurredOn,
        public int $actorId,
        public ?string $ipAddress,
        public ?int $ownerId,
        public string $source,
        public ?string $sourceReturnFileId = null,
        public array $sourceMetadata = [],
        public array $evidence = [],
        public bool $requireArrived = false,
    ) {}
}
