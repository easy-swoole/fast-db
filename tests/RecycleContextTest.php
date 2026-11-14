<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $db->configureQueryStack(true)->selectConnection('idle');
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
        $db = (new FastDb())->addDb(new Config())->configureQueryStack(true);
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

    public function testCoroutineExitCleansAnEmptyContextAfterSelectionAndFailedBorrow(): void
    {
        $db = (new FastDb())->addDb(new Config(), 'failed')->configureQueryStack(true);
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->once())->method('getObj')->willThrowException(new \RuntimeException('borrow failed'));
        $pool->expects($this->never())->method('recycleObj');
        $this->set($db, 'pools', ['failed' => $pool]);

        $finished = new Coroutine\Channel(1);
        foreach ([false, true] as $borrow) {
            Coroutine::create(static function () use ($db, $finished, $borrow): void {
                $cid = Coroutine::getCid();
                Coroutine::defer(static fn () => $finished->push($cid));
                $db->selectConnection('failed');
                if ($borrow) {
                    try { $db->rawQuery('SELECT 1'); }
                    catch (\EasySwoole\FastDb\Exception\RuntimeError) {}
                }
            });
            $cid = $finished->pop(2);
            $this->assertIsInt($cid);
            foreach (['selectConnection', 'currentConnection', 'contextCleanupRegistered'] as $name) {
                $contexts = (new \ReflectionProperty(FastDb::class, $name))->getValue($db);
                $this->assertArrayNotHasKey($cid, $contexts);
            }
        }
    }

    public static function recycleFailures(): array
    {
        return ['pool throws' => ['exception'], 'warning becomes exception' => ['warning'], 'pool rejects' => ['false']];
    }

    #[DataProvider('recycleFailures')]
    public function testFailedReturnDoesNotInterruptOtherReturnsOrCallGc(string $failure): void
    {
        $db = new FastDb();
        $failed = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->onlyMethods(['gc'])->getMock();
        $failed->expects($this->never())->method('gc');
        $active = $this->connection(true);
        $firstPool = $this->createMock(Pool::class);
        $firstPool->expects($this->once())->method('recycleObj')->with($failed)->willReturnCallback(
            static function () use ($failure): bool {
                if ($failure === 'exception') { throw new \RuntimeException('pool recycle failed'); }
                if ($failure === 'warning') { trigger_error('pool warning'); }
                return false;
            }
        );
        $secondPool = $this->createMock(Pool::class);
        $secondPool->expects($this->once())->method('recycleObj')->with($active)->willReturnCallback(
            static function (Connection $client): bool {
                $client->objectRestore();
                return true;
            }
        );
        $this->set($db, 'currentConnection', [Coroutine::getCid() => ['first' => $failed, 'second' => $active]]);
        $this->set($db, 'pools', ['first' => $firstPool, 'second' => $secondPool]);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): never {
            $warnings[] = $message;
            throw new \ErrorException($message, severity: $severity);
        });
        try {
            $db->recycleContext();
        } finally {
            restore_error_handler();
        }

        $this->assertSame($failure === 'warning' ? ['pool warning'] : [], $warnings);
        $this->assertFalse($active->isInTransaction());
        $this->assertSame([], (new \ReflectionProperty(FastDb::class, 'currentConnection'))->getValue($db));
    }

    public static function invokeOutcomes(): array
    {
        return ['business result' => [false], 'business exception' => [true]];
    }

    #[DataProvider('invokeOutcomes')]
    public function testInvokeKeepsTheCallbackOutcomeWhenRecyclingThrows(bool $callbackThrows): void
    {
        $db = (new FastDb())->addDb(new Config());
        $connection = $this->connection(false);
        $pool = $this->createMock(Pool::class);
        $pool->expects($this->once())->method('getObj')->willReturn($connection);
        $pool->expects($this->once())->method('recycleObj')->with($connection)
            ->willThrowException(new \RuntimeException('pool recycle failed'));
        $this->set($db, 'pools', ['default' => $pool]);

        $businessError = new \RuntimeException('business failure');
        $result = $error = null;
        try {
            $result = $db->invoke(static function () use ($callbackThrows, $businessError): string {
                if ($callbackThrows) { throw $businessError; }
                return 'business result';
            });
        } catch (\Throwable $failure) {
            $error = $failure;
        }
        $this->assertSame($callbackThrows ? null : 'business result', $result);
        $this->assertSame($callbackThrows ? $businessError : null, $error);
        $this->assertNull($db->currentConnection());
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
