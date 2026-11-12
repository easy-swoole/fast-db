<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use EasySwoole\Pool\ObjectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ContextReleaseTest extends TestCase
{
    private function database(int $capacity = 1): array
    {
        $config = new Config(['maxObjectNum' => $capacity, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $db = (new FastDb())->addDb($config);
        $pool = new ReleasePool($config);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => $pool]);
        return [$db, $pool];
    }

    public static function modes(): array { return [['invoke'], ['recycle']]; }

    #[DataProvider('modes')]
    public function testRepeatedBorrowingsReturnTheActualClientAndReleaseQueryResults(string $mode): void
    {
        [$db, $pool] = $this->database();
        $results = new \WeakMap();
        try {
            for ($i = 0; $i < 2000; $i++) {
                if ($mode === 'invoke') {
                    $db->invoke(function (Connection $client) use ($db, $results): void {
                        $this->assertSame($client, $db->currentConnection());
                        $results[$db->rawQuery('SELECT 1')] = true;
                    });
                } else {
                    $results[$db->rawQuery('SELECT 1')] = true;
                    $db->recycleContext();
                }
            }
            $this->assertSame(1, $pool->created);
            $this->assertSame(2000, $pool->recycles);
            $this->assertCount(0, $results);
            $this->assertNull($db->currentConnection());
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testInvokeDoesNotReturnAConnectionAlreadyRecycledByItsCallback(): void
    {
        [$db, $pool] = $this->database();
        try {
            $db->invoke(function (Connection $client) use ($db): void {
                $this->assertSame($client, $db->currentConnection());
                $db->recycleContext();
                $this->assertNull($db->currentConnection());
            });
            $this->assertSame(1, $pool->recycles);
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testInvokeLeavesTheSameConnectionCheckedOutWhenBorrowedAgain(): void
    {
        [$db, $pool] = $this->database();
        try {
            $replacement = $db->invoke(function (Connection $client) use ($db): Connection {
                $db->recycleContext();
                $this->assertTrue($db->begin());
                $this->assertSame($client, $db->currentConnection());
                return $client;
            });

            $this->assertSame($replacement, $db->currentConnection());
            $this->assertTrue($db->isInTransaction());
            $this->assertSame(1, $pool->recycles);
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testReturningAnActiveSessionRollsBackBeforeTheNextBorrowing(): void
    {
        [$db, $pool] = $this->database();
        try {
            $this->assertTrue($db->begin());
            $client = $db->currentConnection();
            $this->assertTrue($client->isInTransaction());
            $db->recycleContext();
            $this->assertNull($db->currentConnection());
            $this->assertFalse($client->isInTransaction());
            $this->assertSame(1, $client->rollbacks);
            $db->invoke(function (Connection $borrowed) use ($client): void {
                $this->assertSame($client, $borrowed);
                $this->assertFalse($borrowed->isInTransaction());
            });
            $this->assertSame(2, $pool->recycles);
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testCommitAndRollbackAllowRepeatedBorrowingWithoutKeepingResults(): void
    {
        [$db, $pool] = $this->database();
        $results = new \WeakMap();
        try {
            for ($i = 0; $i < 500; $i++) {
                $this->assertTrue($db->begin());
                $results[$db->rawQuery('SELECT 1')] = true;
                $this->assertTrue($i % 2 === 0 ? $db->commit() : $db->rollback());
                $this->assertFalse($db->isInTransaction());
                $db->recycleContext();
            }
            $this->assertCount(0, $results);
            $this->assertSame(1, $pool->created);
            $this->assertSame(500, $pool->recycles);
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testCoroutineExitReturnsOnlyItsOwnClientAndReleasesItsDiagnostics(): void
    {
        [$db, $pool] = $this->database(2);
        $db->isEnableQueryStack(true);
        $ready = new Coroutine\Channel(2);
        $finished = new Coroutine\Channel(2);
        $resume = [new Coroutine\Channel(1), new Coroutine\Channel(1)];
        $clients = $entries = $errors = [];
        try {
            foreach ([0, 1] as $index) {
                Coroutine::create(function () use ($db, $index, $ready, $finished, $resume, &$clients, &$entries, &$errors): void {
                    // 先登记通知，确保 FastDb 的连接归还和诊断清理先完成。
                    Coroutine::defer(static fn() => $finished->push($index));
                    try {
                        $db->begin();
                        $clients[$index] = $db->currentConnection();
                        $db->rawQuery('SELECT ' . $index);
                        $entries[$index] = \WeakReference::create($db->getQueryStack(-1));
                        $ready->push($index);
                        $resume[$index]->pop(2);
                    } catch (\Throwable $error) { $errors[$index] = $error; }
                });
            }
            $this->assertSame(0, $ready->pop(2));
            $this->assertSame(1, $ready->pop(2));
            $this->assertNotSame($clients[0], $clients[1]);
            $this->assertTrue($clients[0]->isInTransaction());
            $this->assertTrue($clients[1]->isInTransaction());
            $resume[0]->push(true);
            $this->assertSame(0, $finished->pop(2));
            $this->assertFalse($clients[0]->isInTransaction());
            $this->assertTrue($clients[1]->isInTransaction());
            $this->assertNull($entries[0]->get());
            $this->assertNotNull($entries[1]->get());
            $this->assertSame(1, $pool->recycles);
            $resume[1]->push(true);
            $this->assertSame(1, $finished->pop(2));
            $this->assertFalse($clients[1]->isInTransaction());
            $this->assertNull($entries[1]->get());
            $this->assertSame(2, $pool->recycles);
            $this->assertSame([], $errors);
        } finally { $db->reset(); }
    }

    public function testBusinessFailureStillReturnsTheClientWhenTheCoroutineEnds(): void
    {
        [$db, $pool] = $this->database();
        $finished = new Coroutine\Channel(1);
        $client = $failure = null;
        Coroutine::create(static function () use ($db, $finished, &$client, &$failure): void {
            Coroutine::defer(static fn() => $finished->push(true));
            try {
                $db->begin(); $client = $db->currentConnection();
                throw new \RuntimeException('business failure');
            } catch (\Throwable $error) { $failure = $error; }
        });
        try {
            $this->assertTrue($finished->pop(2));
            $this->assertSame('business failure', $failure->getMessage());
            $this->assertSame(1, $pool->recycles);
            $this->assertFalse($client->isInTransaction());
            $this->assertSame(1, $client->rollbacks);
            $this->assertNull($db->currentConnection());
            $this->assertTrue($db->begin());
            $this->assertTrue($db->rollback());
            $db->recycleContext();
        } finally { $db->reset(); }
    }
}

/** 使用真实连接池生命周期，仅替换网络通信，并记录测试会话的事务状态。 */
class ReleaseConnection extends Connection
{
    public int $rollbacks = 0;
    private bool $transactionActive = false;
    public function isInTransaction(): bool { return $this->transactionActive; }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->lastAffectRows = $this->lastInsertId = 0;
        if (str_starts_with($query, 'START TRANSACTION')) { $this->transactionActive = true; }
        elseif (str_starts_with($query, 'ROLLBACK')) { $this->transactionActive = false; $this->rollbacks++; }
        elseif (str_starts_with($query, 'COMMIT')) { $this->transactionActive = false; }
        return true;
    }
    public function beforeUse(): bool { return true; }
    public function intervalCheck(): bool { return true; }
}

class ReleasePool extends Pool
{
    public int $created = 0;
    public int $recycles = 0;
    protected function createObject(): ObjectInterface { $this->created++; return new ReleaseConnection(new DriverConfig()); }
    public function recycleObj($obj): bool { $this->recycles++; return parent::recycleObj($obj); }
}
