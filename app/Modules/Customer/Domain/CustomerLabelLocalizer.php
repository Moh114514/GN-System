<?php

namespace App\Modules\Customer\Domain;

final class CustomerLabelLocalizer
{
    /** @var array<string, string> */
    private const DEFAULT_STAGES = [
        'customer_lifecycle' => '客户生命周期',
    ];

    /** @var array<string, string> */
    private const DEFAULT_STATUSES = [
        'booked' => '已预约',
        'arrived' => '已到院',
        'treatment_completed' => '施术结束',
    ];

    public function stage(string $key, ?string $name): string
    {
        return $this->localize($key, $name, self::DEFAULT_STAGES, 'customers.stages.');
    }

    public function status(string $key, ?string $name): string
    {
        return $this->localize($key, $name, self::DEFAULT_STATUSES, 'customers.statuses.');
    }

    public function statusTranslationKey(string $key, ?string $name): ?string
    {
        return (self::DEFAULT_STATUSES[$key] ?? null) === (string) $name
            ? 'customers.statuses.'.$key
            : null;
    }

    /** @param array<string, string> $defaults */
    private function localize(string $key, ?string $name, array $defaults, string $translationPrefix): string
    {
        $name = (string) $name;

        return ($defaults[$key] ?? null) === $name
            ? (string) __($translationPrefix.$key)
            : $name;
    }
}
