<?php

namespace App\Infrastructure\Localization;

enum SupportedLocale: string
{
    case ZH_CN = 'zh_CN';
    case KO_KR = 'ko_KR';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $locale): string => $locale->value,
            self::cases(),
        );
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('localization.default', self::ZH_CN->value))
            ?? self::ZH_CN;
    }

    public static function fromCandidate(mixed $candidate): ?self
    {
        return is_string($candidate) ? self::tryFrom($candidate) : null;
    }

    public function label(): string
    {
        return (string) config("localization.supported.{$this->value}", $this->value);
    }
}
