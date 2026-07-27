<?php

declare(strict_types=1);

use App\Infrastructure\Database\TestEnvironmentConfiguration;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);

try {
    $configuration = TestEnvironmentConfiguration::fromFile($root.'/.env.testing');
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}

$arguments = array_slice($argv, 1);

if ($arguments === ['--preflight']) {
    fwrite(STDOUT, "测试数据库配置检查通过。\n");
    exit(0);
}

$command = [
    PHP_BINARY,
    'artisan',
    'test',
    ...$arguments,
];

$environment = $configuration->processEnvironment();
$clearConfiguration = new Process(
    command: [PHP_BINARY, 'artisan', 'config:clear', '--ansi'],
    cwd: $root,
    env: $environment,
    timeout: null,
);

$clearExitCode = $clearConfiguration->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
});

if ($clearExitCode !== 0) {
    exit($clearExitCode);
}

$process = new Process(
    command: $command,
    cwd: $root,
    env: $environment,
    timeout: null,
);
$process->setTty(Process::isTtySupported());

exit($process->run(static function (string $type, string $buffer): void {
    fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
}));
