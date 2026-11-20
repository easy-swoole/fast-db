<?php

declare(strict_types=1);

use PHPUnit\TextUI\Application;
use Swoole\Coroutine;
use Swoole\Timer;

require_once dirname(__DIR__) . '/vendor/autoload.php';

chdir(dirname(__DIR__));
$exitCode = 1;
$argv = $_SERVER['argv'];
// PHPUnit 的信息查询命令会直接退出，因此在协程外执行。
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--list-') || in_array($argument, ['--help', '-h', '--version'], true)) {
        exit((new Application())->run($argv));
    }
}
Coroutine\run(function () use ($argv, &$exitCode): void {
    try {
        $exitCode = (new Application())->run($argv);
    } finally {
        Timer::clearAll();
    }
});
exit($exitCode);
