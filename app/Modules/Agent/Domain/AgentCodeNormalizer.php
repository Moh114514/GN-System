<?php

namespace App\Modules\Agent\Domain;

use InvalidArgumentException;

final class AgentCodeNormalizer
{
    public function agent(string $value, ?string $expectedTypeCode = null): string
    {
        $code = strtoupper(trim($value));

        if (preg_match('/^KR-([A-Z0-9]{2,8})$/', $code, $matches) === 1) {
            $code = $matches[1].'-KR';
        }

        if (preg_match('/^[A-Z0-9]{2,8}-([A-Z0-9]{2,4})$/', $code, $matches) !== 1) {
            throw new InvalidArgumentException("无效代理商编号：{$value}");
        }

        if ($expectedTypeCode !== null && $matches[1] !== strtoupper(trim($expectedTypeCode))) {
            throw new InvalidArgumentException('代理商编号必须以 -'.strtoupper(trim($expectedTypeCode)).' 结尾。');
        }

        return $code;
    }

    public function customer(string $value): string
    {
        $code = strtoupper(trim($value));

        if (preg_match('/^KR-([A-Z0-9]{2,8})-(\d{4})$/', $code, $matches) === 1) {
            return "{$matches[1]}-KR-{$matches[2]}";
        }

        if (preg_match('/^[A-Z0-9]{2,8}-[A-Z0-9]{2,4}-\d{4}$/', $code) !== 1
            && preg_match('/^[A-Z0-9]{2,6}-\d{6}$/', $code) !== 1) {
            throw new InvalidArgumentException("无效客户编号：{$value}");
        }

        return $code;
    }
}
