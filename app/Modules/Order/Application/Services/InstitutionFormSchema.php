<?php

namespace App\Modules\Order\Application\Services;

use JsonException;

final class InstitutionFormSchema
{
    public const TEMPLATE_KEY = 'gn-institution-return';

    public const VERSION = 1;

    /** @var array<int, string> */
    public const COLUMNS = [
        'customer_code',
        'customer_name',
        'occurred_on',
        'project_name',
        'specification',
        'quantity',
        'unit_price_krw',
        'amount_krw',
        'notes',
    ];

    /** @var array<int, string> */
    public const HEADERS = [
        '客户编号',
        '客户姓名',
        '消费日期',
        '项目',
        '规格',
        '数量',
        '单价（KRW）',
        '金额（KRW）',
        '业务备注',
    ];

    /** @param array<string, scalar|null> $metadata */
    public static function signature(array $metadata): string
    {
        $metadata = array_map(static fn (mixed $value): string => (string) $value, $metadata);
        ksort($metadata);
        try {
            $payload = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \RuntimeException('机构表单元数据无法签名。', previous: $exception);
        }

        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: $key;
        }

        return hash_hmac('sha256', $payload, $key);
    }
}
