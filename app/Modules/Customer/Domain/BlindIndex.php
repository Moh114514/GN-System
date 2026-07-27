<?php

namespace App\Modules\Customer\Domain;

use RuntimeException;

final class BlindIndex
{
    public function for(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $key = (string) config('data-import.blind_index_key');
        if ($key === '') {
            throw new RuntimeException('BLIND_INDEX_KEY 或 APP_KEY 未配置。');
        }

        $normalized = mb_strtolower(preg_replace('/[\s\-()]+/u', '', trim($value)) ?? trim($value));

        return hash_hmac('sha256', $normalized, $key);
    }
}
