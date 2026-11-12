<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

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

    private function connection(bool $transaction): RecycleContextConnection
    {
        $connection = new RecycleContextConnection(new DriverConfig());
        $connection->transactionActive = $transaction;
        return $connection;
    }

    public function testRecyclingReturnsAllConnectionsAndCleansTheirDatabaseSessions(): void
    {
        $db = new FastDb();
        $idle = $this->connection(false);
        $active = $this->connection(true);
        $pools = [];
        foreach (['idle' => $idle, 'active' => $active] as $name => $connection) {
            $pool = $this->getMockBuilder(Pool::class)->disableOriginalConstructor()->onlyMethods(['recycleObj'])->getMock();
            $pool->expects($this->once())->method('recycleObj')->with($connection)->willReturnCallback(
                function (Connection $client) use ($db, $name): bool {
                    $registered = (new \ReflectionProperty(FastDb::class, 'currentConnection'))->getValue($db);
                    $this->assertArrayNotHasKey($name, $registered[Coroutine::getCid()] ?? []);
                    $client->objectRestore();
                    return true;
                }
            );
            $pools[$name] = $pool;
        }
        $this->set($db, 'currentConnection', [Coroutine::getCid() => ['idle' => $idle, 'active' => $active]]);
        $this->set($db, 'pools', $pools);
        $db->isEnableQueryStack(true)->selectConnection('idle');
        $db->rawQuery('SELECT original_log');
        $db->recycleContext();
        $this->assertFalse($active->isInTransaction());
        $this->assertSame(['ROLLBACK AND NO CHAIN NO RELEASE'], $active->queries);
        $this->assertSame(['SELECT original_log'], $idle->queries);
        $this->assertNull($db->currentConnection());
        $db->selectConnection('active');
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
    }

    public function testRecyclingDoesNotAffectOtherCoroutineSessions(): void
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
        $failure = null;
        Coroutine::create(function () use ($db, $ready, $resume, $finished, $other, &$failure): void {
            Coroutine::defer(static fn () => $finished->push(true));
            try {
                $db->begin();
                $db->rawQuery('SELECT child');
                $ready->push(true);
                $resume->pop(2);
                $this->assertSame($other, $db->currentConnection());
                $this->assertTrue($db->isInTransaction());
                $this->assertSame('SELECT child', $db->getQueryStack(-1)->rawQuery);
            } catch (\Throwable $error) { $failure = $error; $ready->push(false); }
        });
        $this->assertTrue($ready->pop(2));
        $db->recycleContext();
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
        $this->assertTrue($other->isInTransaction());
        $resume->push(true);
        $this->assertTrue($finished->pop(2));
        $this->assertNull($failure);
        $this->assertFalse($other->isInTransaction());
    }

    public function testEmptyContextIsSafe(): void
    {
        $db = new FastDb();
        $db->recycleContext();
        $db->recycleContext();
        $this->assertNull($db->currentConnection());
        $this->assertNull($db->getQueryStack());
    }
}

class RecycleContextConnection extends Connection
{
    public bool $transactionActive = false;
    public array $queries = [];
    public function isInTransaction(): bool { return $this->transactionActive; }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->queries[] = $query;
        $this->lastAffectRows = $this->lastInsertId = 0;
        if (str_starts_with($query, 'START TRANSACTION')) { $this->transactionActive = true; }
        elseif (str_starts_with($query, 'ROLLBACK') || str_starts_with($query, 'COMMIT')) { $this->transactionActive = false; }
        return true;
    }
}
