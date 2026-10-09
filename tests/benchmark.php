<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config as DriverConfig;
use EasySwoole\Mysqli\QueryBuilder;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

$exit = 0;
Coroutine\run(function () use (&$exit): void {
    $admin = new Client(new DriverConfig(MYSQL_CONFIG));
    $table = 'fastdb_bench_' . bin2hex(random_bytes(8));
    $stages = [];
    try {
        $admin->rawQuery("CREATE TABLE `{$table}` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $rows = [];
        for ($id = 1; $id <= 64; $id++) { $rows[] = "({$id},0)"; }
        $admin->rawQuery("INSERT INTO `{$table}` VALUES " . implode(',', $rows));
        $soak = in_array('--soak', $_SERVER['argv'], true);
        foreach ($soak ? ['transaction'] : ['raw_read', 'prepared_read', 'transaction', 'contended_transaction'] as $mode) {
            foreach ($soak ? [64] : [1, 8, 32, 64] as $concurrency) {
                $admin->rawQuery("UPDATE `{$table}` SET value = 0");
                $poolSize = min(32, $concurrency);
                $config = array_replace(MYSQL_CONFIG, ['minObjectNum' => $poolSize - 1, 'maxObjectNum' => $poolSize,
                    'getObjectTimeout' => 5.0, 'intervalCheckTime' => 0]);
                $db = (new FastDb())->addDb(new Config($config));
                $gate = new Channel($concurrency);
                $done = new Channel($concurrency);
                $latencies = []; $errors = []; $commits = 0; $rollbacks = 0; $sql = 0; $attempts = 0;
                $iterations = (int) ceil(($soak ? 16000 : (str_contains($mode, 'transaction') ? 320 : 640)) / $concurrency);
                $db->preConnect();
                $db->invoke(static fn() => $db->rawQuery('SELECT 1'));
                $started = microtime(true);
                try {
                    for ($worker = 0; $worker < $concurrency; $worker++) {
                        Coroutine::create(function () use ($db, $table, $worker, $iterations, $mode, $gate, $done,
                            &$latencies, &$errors, &$commits, &$rollbacks, &$sql, &$attempts): void {
                            $gate->pop(5.0);
                            try {
                                for ($index = 0; $index < $iterations; $index++) {
                                    $t = microtime(true); $attempts++;
                                    try {
                                        // invoke releases after every operation, so 64 workers share a 32-connection pool.
                                        $db->invoke(function () use ($db, $table, $worker, $index, $mode, &$commits, &$rollbacks, &$sql): void {
                                            $id = $mode === 'contended_transaction' ? 1 : $worker + 1;
                                            if (str_contains($mode, 'transaction')) {
                                                $db->begin(timeout: 2.0); $sql++;
                                                $db->rawQuery("UPDATE `{$table}` SET value = value + 1 WHERE id = {$id}", 2.0); $sql++;
                                                if ($index % 4 === 0) { $db->rollback(timeout: 2.0); $rollbacks++; }
                                                else { $db->commit(timeout: 2.0); $commits++; }
                                                $sql++;
                                            } elseif ($mode === 'raw_read') {
                                                $row = $db->rawQuery("SELECT value FROM `{$table}` WHERE id = {$id}", 2.0)->getResultOne(); $sql++;
                                                if ($row['value'] !== 0) { throw new RuntimeException('Unexpected read value'); }
                                            } else {
                                                $builder = new QueryBuilder();
                                                $builder->raw("SELECT value FROM `{$table}` WHERE id = ?", [$id]);
                                                $row = $db->query($builder, 2.0)->getResultOne(); $sql++;
                                                if ($row['value'] !== 0) { throw new RuntimeException('Unexpected prepared read value'); }
                                            }
                                        });
                                        $latencies[] = (microtime(true) - $t) * 1000;
                                    } catch (Throwable $error) {
                                        $errors[] = get_class($error) . ': ' . $error->getMessage();
                                    }
                                }
                            } finally { $db->recycleContext(); $done->push(true); }
                        });
                    }
                    for ($i = 0; $i < $concurrency; $i++) { $gate->push(true); }
                    for ($i = 0; $i < $concurrency; $i++) {
                        if ($done->pop(60.0) !== true) { throw new RuntimeException('Worker did not finish'); }
                    }
                    $seconds = microtime(true) - $started;
                    Coroutine::sleep(0.001);
                    $pool = (new ReflectionProperty(FastDb::class, 'pools'))->getValue($db)['default'];
                    $poolStatus = $pool->status();
                    $contexts = (new ReflectionProperty(FastDb::class, 'currentConnection'))->getValue($db);
                    $activeContexts = count(array_filter($contexts));
                    sort($latencies);
                    $percentile = static fn(float $p) => $latencies[max(0, (int) ceil(count($latencies) * $p) - 1)] ?? null;
                    $actual = $admin->rawQuery("SELECT SUM(value) AS total FROM `{$table}`")[0]['total'];
                    $correct = (int) $actual === $commits;
                    $stage = ['mode' => $mode, 'concurrency' => $concurrency, 'pool_size' => $poolSize,
                        'operations' => $attempts, 'successes' => count($latencies), 'errors' => count($errors),
                        'seconds' => round($seconds, 3), 'operations_per_second' => round(count($latencies) / $seconds, 2),
                        'sql_per_second' => round($sql / $seconds, 2), 'p50_ms' => $percentile(0.50),
                        'p95_ms' => $percentile(0.95), 'p99_ms' => $percentile(0.99),
                        'commits' => $commits, 'rollbacks' => $rollbacks, 'observed_sum' => (int) $actual,
                        'data_correct' => $correct, 'created_connections' => $poolStatus['createdNum'],
                        'active_contexts_after_workers' => $activeContexts, 'peak_memory_bytes' => memory_get_peak_usage(true), 'error_samples' => array_slice(array_unique($errors), 0, 5)];
                    $stages[] = $stage;
                    echo json_encode($stage, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
                    if ($errors || !$correct || $poolStatus['createdNum'] > $poolSize || $activeContexts !== 0) { $exit = 1; break 2; }
                } finally { $db->recycleContext(); $db->reset(); }
            }
        }
    } catch (Throwable $error) {
        $exit = 1; echo get_class($error), ': ', $error->getMessage(), PHP_EOL;
    } finally {
        $admin->rawQuery("DROP TABLE IF EXISTS `{$table}`");
        $admin->close();
        Swoole\Timer::clearAll();
        if (isset($_SERVER['argv'][1])) {
            file_put_contents($_SERVER['argv'][1], json_encode(['stages' => $stages, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
});
exit($exit);
