<?php

namespace App\Modules\Settlement\Application\Exceptions;

use DomainException;

final class StructuredSettlementFailure extends DomainException
{
    /** @param array<string, scalar> $parameters */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $parameters = [],
    ) {
        parent::__construct($messageKey);
    }
}
