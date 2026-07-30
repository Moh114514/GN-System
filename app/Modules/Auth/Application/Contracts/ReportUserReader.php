<?php

namespace App\Modules\Auth\Application\Contracts;

interface ReportUserReader
{
    /**
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    public function namesByIds(array $ids): array;
}
