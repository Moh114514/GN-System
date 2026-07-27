<?php

namespace App\Modules\Audit\Application\Contracts;

interface AuditRecorder
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function record(string $description, array $properties = [], ?int $causerId = null): void;
}
