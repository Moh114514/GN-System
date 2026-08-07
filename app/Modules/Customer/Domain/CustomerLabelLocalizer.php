<?php

namespace App\Modules\Customer\Domain;

final class CustomerLabelLocalizer
{
    /** @var array<string, string> */
    private const DEFAULT_STAGES = [
        'first_contact' => '首次接触',
        'booking' => '预约确认',
        'arrival' => '到院接待',
        'followup' => '后续跟进',
        'operations' => '运营管理',
    ];

    /** @var array<string, string> */
    private const DEFAULT_STATUSES = [
        'interested' => '意向',
        'quoted' => '已报价',
        'booked' => '已预约',
        'arrived' => '已到院',
        'returned_home' => '已回国',
        'dormant' => '沉默待唤醒',
        'lost' => '已流失',
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
