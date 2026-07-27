<?php

namespace App\Modules\Audit\Application\Contracts;

use App\Modules\Audit\Application\Data\AuditEntryData;
use Illuminate\Database\Eloquent\Model;

interface AuditRecorder
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(
        string $description,
        array $properties = [],
        ?int $causerId = null,
        ?Model $subject = null,
        string $logName = 'data-import',
        ?string $event = null,
        ?string $ipAddress = null,
    ): void;

    /** @return array<int, AuditEntryData> */
    public function trail(Model $subject, string $logName): array;
}
