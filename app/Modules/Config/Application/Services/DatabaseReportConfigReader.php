<?php

namespace App\Modules\Config\Application\Services;

use App\Modules\Config\Application\Contracts\ReportConfigReader;
use App\Modules\Config\Infrastructure\Models\Institution;
use App\Modules\Config\Infrastructure\Models\SystemParameter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DatabaseReportConfigReader implements ReportConfigReader
{
    public function institutionNamesByIds(array $ids): array
    {
        return Institution::query()->whereKey(array_values(array_unique($ids)))->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(int) $id => (string) $name])->all();
    }

    public function institutionIdsOrderedByName(): array
    {
        return Institution::query()->orderBy('name')->orderBy('id')->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    public function activeInstitutions(): array
    {
        return Institution::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Institution $institution): array => [
                'id' => (int) $institution->id,
                'name' => (string) $institution->name,
            ])->all();
    }

    public function integerParameter(string $key, int $default): int
    {
        try {
            return (int) Cache::remember(
                "system-parameter:{$key}",
                now()->addMinutes(5),
                fn (): int => $this->readInteger($key, $default),
            );
        } catch (Throwable $exception) {
            Log::warning('系统参数缓存读取失败，已回退数据库。', [
                'key' => $key,
                'exception' => $exception->getMessage(),
            ]);

            return $this->readInteger($key, $default);
        }
    }

    private function readInteger(string $key, int $default): int
    {
        $value = SystemParameter::query()->whereKey($key)->value('value');
        if (is_string($value)) {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        return is_int($value) ? $value : $default;
    }
}
