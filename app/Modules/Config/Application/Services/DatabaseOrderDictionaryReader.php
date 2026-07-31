<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Config\Application\Contracts\OrderDictionaryReader;
use App\Modules\Config\Infrastructure\Models\DictionaryItem;

final class DatabaseOrderDictionaryReader implements OrderDictionaryReader
{
    public function activeItems(string $type): array
    {
        return DictionaryItem::query()->where('type', $type)->where('is_active', true)->orderBy('name')
            ->get(['id', 'code', 'name'])->map(fn (DictionaryItem $item): array => [
                'id' => (int) $item->id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
            ])->all();
    }

    public function activeItem(int $id, string $type): array
    {
        $item = DictionaryItem::query()->where('type', $type)->where('is_active', true)->findOrFail($id);

        return ['id' => (int) $item->id, 'code' => (string) $item->code, 'name' => (string) $item->name];
    }
}
