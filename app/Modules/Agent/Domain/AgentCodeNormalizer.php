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
            throw new InvalidArgumentException($this->message('agents.validation.invalid_agent_code', ['value' => $value], '代理商编号格式无效。'));
        }

        if ($expectedTypeCode !== null && $matches[1] !== strtoupper(trim($expectedTypeCode))) {
            throw new InvalidArgumentException($this->message('agents.validation.agent_code_type_suffix', ['type' => strtoupper(trim($expectedTypeCode))], '代理商编号类型后缀不匹配。'));
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
            throw new InvalidArgumentException($this->message('agents.validation.invalid_customer_code', ['value' => $value], '客户编号格式无效。'));
        }

        return $code;
    }

    /** @param array<string, scalar> $parameters */
    private function message(string $key, array $parameters, string $fallback): string
    {
        return function_exists('app') && app()->bound('translator')
            ? (string) __($key, $parameters)
            : $fallback;
    }
}
