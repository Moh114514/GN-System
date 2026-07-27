<?php

namespace App\Modules\Customer\Domain;

final class SensitiveValueMasker
{
    public function mask(?string $value, int $prefix = 3, int $suffix = 4): string
    {
        if ($value === null || trim($value) === '') {
            return '—';
        }

        $value = trim($value);
        $length = mb_strlen($value);
        if ($length <= $prefix + $suffix) {
            return mb_substr($value, 0, 1).str_repeat('*', max(3, $length - 1));
        }

        return mb_substr($value, 0, $prefix)
            .str_repeat('*', min(6, $length - $prefix - $suffix))
            .mb_substr($value, -$suffix);
    }
}
