<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class RecycleContextTest extends TestCase
{
    private function set(FastDb $db, string $name, array $value): void
    {
        (new \ReflectionProperty(FastDb::class, $name))->setValue($db, $value);
    }

    private function get(FastDb $db, string $name): array
    {
        return (new \ReflectionProperty(FastDb::class, $name))->getValue($db);
    }

    private function connection(bool $transaction): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->isInTransaction = $transaction;
        return $connection;
    }

    public function testDefaultCheckRejectsEntireContextWithoutSideEffects(): void
    {
        $db = new FastDb();
        $idle = $this->connection(false);
        $active = $this->connection(true);
        $connections = [Coroutine::getCid() => ['idle' => $idle, 'active' => $active]];
        $stack = [Coroutine::getCid() => ['original log']];
        $pools = [];
        foreach(['idle', 'active'] as $name){
            $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['recycleObj'])->getMock();
            $pool->expects($this->never())->method('recycleObj');
            $pools[$name] = $pool;
        }
        $this->set($db, 'currentConnection', $connections);
        $this->set($db, 'queryStack', $stack);
        $this->set($db, 'pools', $pools);
        try {
            $db->recycleContext();
            $this->fail('Expected active transaction to prevent recycling');
        } catch (RuntimeError $error) {
            $this->assertStringContainsString('active transaction', $error->getMessage());
            $this->assertStringContainsString('connection active', $error->getMessage());
        }
        $this->assertSame($connections, $this->get($db, 'currentConnection'));
        $this->assertSame($stack, $this->get($db, 'queryStack'));
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
        $this->set($db, 'queryStack', [Coroutine::getCid() => ['original log']]);
        $db->recycleContext(checkTransaction: false);
        $this->assertSame([], $this->get($db, 'currentConnection'));
        $this->assertSame([], $this->get($db, 'queryStack'));
    }

    public function testIdleContextRecyclesWithoutCheckingOtherCoroutines(): void
    {
        $db = new FastDb();
        $idle = $this->connection(false);
        $other = $this->connection(true);
        $cid = Coroutine::getCid();
        $otherCid = $cid + 1000;
        $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['recycleObj'])->getMock();
        $pool->expects($this->once())->method('recycleObj')->with($idle)->willReturn(true);
        $this->set($db, 'pools', ['default' => $pool]);
        $this->set($db, 'currentConnection', [$cid => ['default' => $idle], $otherCid => ['default' => $other]]);
        $this->set($db, 'queryStack', [$cid => ['current'], $otherCid => ['other']]);
        $db->recycleContext(checkTransaction: true);
        $this->assertSame([$otherCid => ['default' => $other]], $this->get($db, 'currentConnection'));
        $this->assertSame([$otherCid => ['other']], $this->get($db, 'queryStack'));
    }

    public function testEmptyContextIsSafe(): void
    {
        $db = new FastDb();
        $db->recycleContext();
        $db->recycleContext(false);
        $this->assertSame([], $this->get($db, 'currentConnection'));
    }
}
