<?php

namespace App\Modules\Auth\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;

final class AdminCommandSupport
{
    public static function reason(Command $command): string
    {
        $reason = trim((string) $command->option('reason'));
        if ($reason === '') {
            throw new InvalidArgumentException('A non-empty --reason is required.');
        }
        if (mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The reason must not exceed 500 characters.');
        }

        return $reason;
    }

    public static function operator(Command $command): string
    {
        $operator = trim((string) $command->option('operator'));
        if ($operator === '') {
            throw new InvalidArgumentException('A non-empty --operator identifier is required.');
        }
        if (mb_strlen($operator) > 128) {
            throw new InvalidArgumentException('The operator identifier must not exceed 128 characters.');
        }

        return $operator;
    }
}
