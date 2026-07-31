<?php

namespace App\Modules\Config\Application\Contracts;

interface OrderDictionaryReader
{
    /** @return array<int, array{id: int, code: string, name: string}> */
    public function activeItems(string $type): array;

    /** @return array{id: int, code: string, name: string} */
    public function activeItem(int $id, string $type): array;
}
