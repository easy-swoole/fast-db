<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class RecycleContextTest extends TestCase
{
    private function set(FastDb $db, string $name, array $value): void
    {
        (new \ReflectionProperty(FastDb::class, $name))->setValue($db, $value);
    }

    private function connection(bool $transaction): Connection
    {
        $connection = new RecycleContextConnection(new DriverConfig());
        $connection->isInTransaction = $transaction;
        return $connection;
    }

    public function testDefaultCheckRejectsEntireContextWithoutSideEffects(): void
    {
        $db = new FastDb();
        $idle = $this->connection(false);
        $active = $this->connection(true);
        $connections = [Coroutine::getCid() => ['idle' => $idle, 'active' => $active]];
        $pools = [];
        foreach(['idle', 'active'] as $name){
            $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['recycleObj'])->getMock();
            $pool->expects($this->never())->method('recycleObj');
            $pools[$name] = $pool;
        }
        $this->set($db, 'currentConnection', $connections);
        $this->set($db, 'pools', $pools);
        $db->isEnableQueryStack(true)->selectConnection('idle');
        $db->rawQuery('SELECT original_log');
        $stack = $db->getQueryStack();
        try {
            $db->recycleContext();
            $this->fail('Expected active transaction to prevent recycling');
        } catch (RuntimeError $error) {
            $this->assertStringContainsString('active transaction', $error->getMessage());
            $this->assertStringContainsString('connection active', $error->getMessage());
        }
        $this->assertSame($idle, $db->currentConnection());
        $db->selectConnection('active');
        $this->assertSame($active, $db->currentConnection());
        $this->assertSame($stack, $db->getQueryStack());
        $this->assertTrue($active->isInTransaction);
    }

    public function testFalseExplicitlyRecyclesActiveTransactions(): void
    {
        $db = new FastDb();
        $active = $this->connection(true);
        $idle = $this->connection(false);
        $pools = [];
        foreach(['active' => $active, 'idle' => $idle] as $name => $connection){
            $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['recycleObj'])->getMock();
            $pool->expects($this->once())->method('recycleObj')->with($connection)->willReturn(true);
            $pools[$name] = $pool;
        }
        $this->set($db, 'currentConnection', [Coroutine::getCid() => ['active' => $active, 'idle' => $idle]]);
        $this->set($db, 'pools', $pools);
        $db->isEnableQueryStack(true)->selectConnection('idle');
        $db->rawQuery('SELECT original_log');
        $db->recycleContext(checkTransaction: false);
        $this->assertNull($db->currentConnection());
        $db->selectConnection('active');
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
    }

    public function testIdleContextRecyclesWithoutCheckingOtherCoroutines(): void
    {
        $db = (new FastDb())->addDb(new Config())->isEnableQueryStack(true);
        $idle = $this->connection(false);
        $other = $this->connection(false);
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['getObj', 'recycleObj'])->getMock();
        $pool->expects($this->exactly(2))->method('getObj')->willReturnOnConsecutiveCalls($idle, $other);
        $pool->expects($this->exactly(2))->method('recycleObj')->willReturnCallback(static function (Connection $client): bool {
            $client->objectRestore();
            return true;
        });
        $this->set($db, 'pools', ['default' => $pool]);
        $db->rawQuery('SELECT parent');
        $ready = new Coroutine\Channel(1);
        $resume = new Coroutine\Channel(1);
        $finished = new Coroutine\Channel(1);
        $result = null;
        Coroutine::create(function () use ($db, $ready, $resume, $finished, $other, &$result): void {
            Coroutine::defer(static fn() => $finished->push(true));
            try {
                $db->begin();
                $db->rawQuery('SELECT child');
                $ready->push(true);
                $resume->pop(2);
                $this->assertSame($other, $db->currentConnection());
                $this->assertTrue($db->isInTransaction());
                $this->assertSame('SELECT child', $db->getQueryStack(-1)->rawQuery);
            } catch (\Throwable $error) { $result = $error; $ready->push(false); }
        });
        $this->assertTrue($ready->pop(2));
        $db->recycleContext(checkTransaction: true);
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
        $this->assertTrue($other->isInTransaction);
        $resume->push(true);
        $this->assertTrue($finished->pop(2));
        $this->assertNull($result);
        $this->assertFalse($other->isInTransaction);
    }

    public function testEmptyContextIsSafe(): void
    {
        $db = new FastDb();
        $db->recycleContext();
        $db->recycleContext(false);
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
    }
}

class RecycleContextConnection extends Connection
{
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->lastAffectRows = $this->lastInsertId = 0;
        return true;
    }
}
