<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config as ClientConfig;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\Exception\PoolEmpty;
use EasySwoole\Pool\ObjectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ConnectionAcquireTimingTest extends TestCase
{
    private array $databases = [];

    protected function tearDown(): void
    {
        foreach ($this->databases as $db) { $db->recycleContext(); }
    }

    public static function queryModes(): array { return [[true], [false]]; }

    #[DataProvider('queryModes')]
    public function testEveryNewBorrowHasItsOwnEventAndReusedQueriesDoNotBorrow(bool $raw): void
    {
        [$db, $pools] = $this->database();
        $observed = [];
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
        $first = $this->query($db, $raw, 'SELECT 1');
        $second = $this->query($db, !$raw, 'SELECT 2');
        $this->assertSame([$first, $second], $observed);
        $this->assertSame(1, $pools['default']->borrows);
        $stack = $db->getQueryStack();
        $this->assertSame(['connectionAcquire', 'query', 'query'], array_column($stack, 'type'));
        $this->assertGreaterThanOrEqual(0.012, $stack[0]->duration);
        $this->assertNull($stack[0]->query);
        $this->assertNull($stack[0]->rawQuery);
        $this->assertNull($stack[0]->exception);
        $this->assertSame($first->getDuration(), $stack[1]->duration);
        $this->assertSame($second->getDuration(), $stack[2]->duration);
        $this->assertLessThan($stack[0]->duration, $first->getDuration());
    }

    public function testFactoryReentryRecordsEventsInCompletionOrderWithOriginalConnectionNames(): void
    {
        [$db, $pools] = $this->database(['default', 'other']);
        $inner = null;
        $outer = $db->query(function (QueryBuilder $builder) use ($db, &$inner): void {
            $db->selectConnection('other');
            $inner = $db->rawQuery('SELECT inner_query');
            $builder->raw('SELECT outer_query');
        });
        $this->assertSame('default', $outer->getConnectionName());
        $this->assertSame('other', $inner->getConnectionName());
        $this->assertSame('other', $db->selectConnection());
        $db->rawQuery('SELECT reused');
        $stack = $db->getQueryStack();
        $this->assertSame(['connectionAcquire', 'connectionAcquire', 'query', 'query', 'query'], array_column($stack, 'type'));
        $this->assertSame(['default', 'other', 'other', 'default', 'other'], array_column($stack, 'connectionName'));
        $this->assertGreaterThanOrEqual(0.012, $stack[0]->duration);
        $this->assertGreaterThanOrEqual(0.012, $stack[1]->duration);
        $this->assertSame(1, $pools['default']->borrows);
        $this->assertSame(1, $pools['other']->borrows);
    }

    public function testPoolCallbackReentryFinishesItsEventsBeforeTheOuterAcquire(): void
    {
        [$db, $pools] = $this->database(['default', 'other']);
        $pools['default']->onAcquire = static function () use ($db): void {
            $db->selectConnection('other');
            $db->rawQuery('SELECT health_callback');
        };
        $outer = $db->rawQuery('SELECT outer_query');
        $stack = $db->getQueryStack();
        $this->assertSame('default', $outer->getConnectionName());
        $this->assertSame(['connectionAcquire', 'query', 'connectionAcquire', 'query'], array_column($stack, 'type'));
        $this->assertSame(['other', 'other', 'default', 'default'], array_column($stack, 'connectionName'));
        $this->assertGreaterThan($stack[0]->duration, $stack[2]->duration);
        $this->assertSame('other', $db->selectConnection());
    }

    public static function acquireFailures(): array { return [['exception'], ['null']]; }

    #[DataProvider('acquireFailures')]
    public function testAcquireFailureLogsTheActualEscapingExceptionWithoutQueryNotification(string $failureMode): void
    {
        [$db, $pools, $clients] = $this->database();
        $original = new \RuntimeException('health check failed', 31);
        $pools['default']->failure = $failureMode === 'exception' ? $original : false;
        $notifications = 0;
        $db->setOnQuery(static function () use (&$notifications): void { $notifications++; });
        $caught = null;
        try { $db->rawQuery('SELECT never_sent'); }
        catch (RuntimeError $error) { $caught = $error; }
        $this->assertInstanceOf(RuntimeError::class, $caught);
        $this->assertSame($failureMode === 'exception' ? $original : null, $caught->getPrevious());
        $this->assertSame(0, $notifications);
        $this->assertSame([], $clients['default']->sql);
        $stack = $db->getQueryStack();
        $this->assertCount(1, $stack);
        $this->assertSame('connectionAcquire', $stack[0]->type);
        $this->assertSame('default', $stack[0]->connectionName);
        $this->assertSame($caught, $stack[0]->exception);
        $this->assertNull($stack[0]->rawQuery);
        $this->assertNull($stack[0]->query);
        $this->assertGreaterThanOrEqual(0.012, $stack[0]->duration);
        $this->assertGreaterThanOrEqual($stack[0]->startTime, $stack[0]->endTime);
        $this->assertNull($db->currentConnection());
        $db->recycleContext();
        $this->assertNull($db->getQueryStack());
    }

    public function testInvokeLogsOneIndependentAcquireEventBeforeItsCallback(): void
    {
        [$db, $pools] = $this->database();
        $observed = [];
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
        $result = $db->invoke(function (Connection $client) use ($db, $pools): string {
            $stack = $db->getQueryStack();
            $this->assertCount(1, $stack);
            $this->assertSame('connectionAcquire', $stack[0]->type);
            $this->assertNull($stack[0]->exception);
            $this->assertGreaterThanOrEqual(0.012, $stack[0]->duration);
            $db->rawQuery('SELECT invoke_callback');
            $db->invoke(function (Connection $nested) use ($client, $db): void {
                $this->assertSame($client, $nested);
                $db->rawQuery('SELECT nested_invoke');
            });
            $this->assertSame(0, $pools['default']->recycles);
            return 'callback_result';
        });
        $this->assertSame('callback_result', $result);
        $this->assertCount(2, $observed);
        $this->assertSame(1, $pools['default']->borrows);
        $this->assertSame(1, $pools['default']->recycles);
        $this->assertNull($db->currentConnection());
        $this->assertSame(['connectionAcquire', 'query', 'query'], array_column($db->getQueryStack(), 'type'));
    }

    public function testInvokeAcquireFailureDoesNotRunTheCallbackOrRecycleAnUnborrowedClient(): void
    {
        [$db, $pools] = $this->database();
        $pools['default']->failure = false;
        $entered = false;
        $caught = null;
        try { $db->invoke(static function () use (&$entered): void { $entered = true; }); }
        catch (RuntimeError $error) { $caught = $error; }
        $this->assertInstanceOf(RuntimeError::class, $caught);
        $this->assertFalse($entered);
        $this->assertSame(0, $pools['default']->recycles);
        $this->assertCount(1, $db->getQueryStack());
        $this->assertSame($caught, $db->getQueryStack(0)->exception);
    }

    public function testManagedTransactionRecordsOneAcquireEventAndOnlySqlReachesTheObserver(): void
    {
        [$db, $pools] = $this->database();
        $observed = [];
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
        $this->assertSame('done', $db->transaction(function (FastDb $db): string {
            $db->rawQuery('SELECT in_transaction');
            $db->transaction(function (FastDb $db): void { $db->query((new QueryBuilder())->raw('SELECT joined')); });
            return 'done';
        }));
        $this->assertCount(4, $observed);
        $this->assertStringStartsWith('START TRANSACTION', $observed[0]->getRawSql());
        $this->assertSame(['connectionAcquire', 'query', 'query', 'query', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertGreaterThanOrEqual(0.012, $db->getQueryStack(0)->duration);
        $this->assertSame(1, $pools['default']->borrows);
    }

    public function testManagedTransactionPreservesTheVirtualBeginCall(): void
    {
        $custom = new AcquireTimingBeginOverrideDb();
        [$db, $pools] = $this->database(db: $custom);
        $db->transaction(static function (): void {});
        $this->assertSame(1, $custom->begins);
        $this->assertSame(['connectionAcquire', 'query', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertSame(1, $pools['default']->borrows);
    }

    public function testManualBeginUsesTheSameEventModelAndAnExplicitClientDoesNotBorrow(): void
    {
        [$db, $pools] = $this->database();
        $this->assertTrue($db->begin());
        $this->assertSame(['connectionAcquire', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertGreaterThanOrEqual(0.012, $db->getQueryStack(0)->duration);
        $this->assertTrue($db->rollback());
        $explicit = new AcquireTimingClient();
        $explicit->connectionName = 'external';
        $this->assertTrue($db->begin($explicit));
        $this->assertSame(['connectionAcquire', 'query', 'query', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertSame('external', $db->getQueryStack(-1)->connectionName);
        $this->assertSame(1, $pools['default']->borrows);
        $db->rollback($explicit);
    }

    public function testFoundRowsHasItsOwnSqlEventWithoutAnotherAcquire(): void
    {
        [$db, $pools, $clients] = $this->database();
        $observed = [];
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
        $builder = (new QueryBuilder())->setQueryOption('SQL_CALC_FOUND_ROWS')->get('rows', 1);
        $result = $db->query($builder);
        $this->assertSame(37, $db->getTotalCount($result));
        $this->assertSame([$result, $db->getTotalResult($result)], $observed);
        $this->assertSame(['connectionAcquire', 'query', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertCount(2, $clients['default']->sql);
        $this->assertSame(1, $pools['default']->borrows);
    }

    public function testSqlFailureAndSuccessfulAcquireHaveSeparateExceptionState(): void
    {
        [$db, $pools, $clients] = $this->database();
        $failure = new \RuntimeException('query failed');
        $clients['default']->queryFailure = $failure;
        $observed = null;
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed = $result; });
        try { $db->rawQuery('SELECT fails'); $this->fail('Expected SQL error'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame($failure, $observed->getException());
        $this->assertSame(['connectionAcquire', 'query'], array_column($db->getQueryStack(), 'type'));
        $this->assertNull($db->getQueryStack(0)->exception);
        $this->assertSame($failure, $db->getQueryStack(1)->exception);
        $clients['default']->queryFailure = null;
        $db->rawQuery('SELECT after_failure');
        $this->assertSame(1, $pools['default']->borrows);
        $this->assertSame('query', $db->getQueryStack(-1)->type);
    }

    public function testDefaultQueriesAndReadOnlyDiagnosticsDoNotAllocateTheDiagnosticsObject(): void
    {
        [$db] = $this->database(stack: false);
        $diagnostics = new \ReflectionProperty(FastDb::class, 'diagnostics');
        $this->assertNull($diagnostics->getValue($db));
        $this->assertNull($db->getQueryStack());
        $db->rawQuery('SELECT success');
        $this->assertNull($diagnostics->getValue($db));
        $db->recycleContext();
        $this->assertNull($diagnostics->getValue($db));
    }

    public function testDisabledStackLeavesNoEventsButStillCallsTheSqlObserver(): void
    {
        [$db, $pools] = $this->database();
        $db->isEnableQueryStack(false);
        $observed = [];
        $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
        $result = $db->rawQuery('SELECT success');
        $this->assertSame([$result], $observed);
        $this->assertNull($db->getQueryStack());
        $db->recycleContext();
        $pools['default']->failure = false;
        try { $db->rawQuery('SELECT never_sent'); $this->fail('Expected borrowing failure'); }
        catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertNull($db->getQueryStack());
        $this->assertSame([$result], $observed);
    }

    public function testDisablingTheStackDuringAcquisitionDropsTheStartedAcquireEvent(): void
    {
        [$db, $pools] = $this->database();
        $pools['default']->onAcquire = static fn () => $db->isEnableQueryStack(false);
        $db->rawQuery('SELECT after_disabling');
        $this->assertNull($db->getQueryStack());
        $db->isEnableQueryStack(true);
        $db->rawQuery('SELECT reused');
        $this->assertSame(['query'], array_column($db->getQueryStack(), 'type'));
    }

    public function testEnablingTheStackDuringAcquisitionDoesNotInventAnAcquireStartTime(): void
    {
        [$db, $pools] = $this->database(stack: false);
        $pools['default']->onAcquire = static fn () => $db->isEnableQueryStack(true);
        $db->rawQuery('SELECT after_enabling');
        $this->assertSame(['query'], array_column($db->getQueryStack(), 'type'));
        $this->assertSame('SELECT after_enabling', $db->getQueryStack(0)->rawQuery);
    }

    public function testFactoryCanFirstEnableDiagnosticsForItsCurrentQueryWithoutAnAcquireEvent(): void
    {
        [$db, $pools] = $this->database(stack: false);
        $observed = [];
        $result = $db->query(static function (QueryBuilder $builder) use ($db, &$observed): void {
            $db->setOnQuery(static function (QueryResult $result) use (&$observed): void { $observed[] = $result; });
            $db->isEnableQueryStack(true);
            $builder->raw('SELECT enabled_in_factory');
        });
        $this->assertSame([$result], $observed);
        $this->assertSame(['query'], array_column($db->getQueryStack(), 'type'));
        $this->assertSame('SELECT enabled_in_factory', $db->getQueryStack(0)->query->getLastPrepareQuery());
        $this->assertSame(1, $pools['default']->borrows);
    }

    public function testAnAcquireFailureAfterMidBorrowEnablingDoesNotInventAnEvent(): void
    {
        [$db, $pools] = $this->database(stack: false);
        $pools['default']->onAcquire = static fn () => $db->isEnableQueryStack(true);
        $pools['default']->failure = false;
        try { $db->rawQuery('SELECT never_sent'); $this->fail('Expected borrowing failure'); }
        catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertNull($db->getQueryStack());
    }

    public function testRealPoolIncludesCreationAndEveryBeforeUseRetry(): void
    {
        $clients = [new AcquireTimingClient(), new AcquireTimingClient()];
        $checks = 0;
        foreach ($clients as $index => $client) {
            $client->healthCheck = static function () use (&$checks, $index): bool {
                $checks++;
                Coroutine::sleep(0.012);
                return $index === 1;
            };
        }
        $config = new Config(['maxObjectNum' => 1, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $pool = new AcquireTimingRetryPool($config, $clients);
        $db = (new FastDb())->addDb($config)->isEnableQueryStack(true);
        $this->databases[] = $db;
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => $pool]);
        try {
            $db->rawQuery('SELECT after_health_retry');
            $this->assertSame(2, $checks);
            $this->assertSame(2, $pool->created);
            $this->assertSame($clients[1], $db->currentConnection());
            $this->assertSame(['connectionAcquire', 'query'], array_column($db->getQueryStack(), 'type'));
            $this->assertGreaterThanOrEqual(0.040, $db->getQueryStack(0)->duration);
        } finally { $db->recycleContext(); $pool->destroy(); }
    }

    public function testRealPoolIncludesChannelWaitingTime(): void
    {
        [$db, $pool] = $this->waitingDatabase();
        $busy = $pool->getObj();
        $released = new Coroutine\Channel(1);
        Coroutine::create(static function () use ($pool, $busy, $released): void {
            Coroutine::sleep(0.025);
            try { $pool->recycleObj($busy); $released->push(null); }
            catch (\Throwable $error) { $released->push($error); }
        });
        try {
            $db->rawQuery('SELECT after_wait');
            $this->assertGreaterThanOrEqual(0.020, $db->getQueryStack(0)->duration);
            $this->assertSame('connectionAcquire', $db->getQueryStack(0)->type);
            $this->assertSame($busy, $db->currentConnection());
            $this->assertSame(1, $pool->created);
        } finally {
            $this->assertNull($released->pop(0.2));
            $db->recycleContext();
            $pool->destroy();
        }
    }

    public function testRealPoolChannelTimeoutLogsItsWholeWaitAndOriginalCause(): void
    {
        [$db, $pool] = $this->waitingDatabase(0.015);
        $busy = $pool->getObj();
        $caught = null;
        try {
            try { $db->rawQuery('SELECT never_sent'); }
            catch (RuntimeError $error) { $caught = $error; }
            $this->assertInstanceOf(RuntimeError::class, $caught);
            $this->assertInstanceOf(PoolEmpty::class, $caught->getPrevious());
            $this->assertCount(1, $db->getQueryStack());
            $event = $db->getQueryStack(0);
            $this->assertSame('connectionAcquire', $event->type);
            $this->assertSame($caught, $event->exception);
            $this->assertGreaterThanOrEqual(0.012, $event->duration);
            $this->assertSame(1, $pool->created);
        } finally { $db->recycleContext(); $pool->recycleObj($busy); $pool->destroy(); }
    }

    private function database(array $names = ['default'], ?FastDb $db = null, bool $stack = true): array
    {
        $db ??= new FastDb();
        if ($stack) { $db->isEnableQueryStack(true); }
        $this->databases[] = $db;
        $pools = $clients = [];
        foreach ($names as $name) {
            $config = new Config();
            $db->addDb($config, $name);
            $clients[$name] = new AcquireTimingClient();
            $pools[$name] = new AcquireTimingPool($config, $clients[$name]);
        }
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, $pools);
        return [$db, $pools, $clients];
    }

    private function waitingDatabase(float $timeout = 0.1): array
    {
        $config = new Config(['maxObjectNum' => 1, 'minObjectNum' => 0, 'intervalCheckTime' => 0, 'getObjectTimeout' => $timeout]);
        $pool = new AcquireTimingRetryPool($config, [new AcquireTimingClient()]);
        $db = (new FastDb())->addDb($config)->isEnableQueryStack(true);
        $this->databases[] = $db;
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => $pool]);
        return [$db, $pool];
    }

    private function query(FastDb $db, bool $raw, string $sql): QueryResult
    {
        return $raw ? $db->rawQuery($sql) : $db->query((new QueryBuilder())->raw($sql));
    }
}

class AcquireTimingClient extends Connection
{
    private bool $transactionActive = false;
    public array $sql = [];
    public ?\Throwable $queryFailure = null;
    public ?\Closure $healthCheck = null;
    public function __construct() { parent::__construct(new ClientConfig()); }
    public function isInTransaction(): bool { return $this->transactionActive; }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array { return $this->rawQuery($builder->getLastPrepareQuery(), $timeout); }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->sql[] = $query;
        if ($this->queryFailure !== null) { throw $this->queryFailure; }
        $this->lastAffectRows = $this->lastInsertId = 0;
        if (str_starts_with($query, 'START TRANSACTION')) { $this->transactionActive = true; return true; }
        if (str_starts_with($query, 'COMMIT') || str_starts_with($query, 'ROLLBACK')) { $this->transactionActive = false; return true; }
        return $query === 'SELECT FOUND_ROWS() as count' ? [['count' => 37]] : [['id' => 1]];
    }
    public function beforeUse(): bool { return $this->healthCheck === null || ($this->healthCheck)(); }
}

class AcquireTimingPool extends Pool
{
    public int $borrows = 0;
    public int $recycles = 0;
    public \Throwable|false|null $failure = null;
    public ?\Closure $onAcquire = null;
    public function __construct(Config $config, private Connection $client) { parent::__construct($config); }
    public function getObj(?float $timeout = null, int $tryTimes = 3): ?ObjectInterface
    {
        $this->borrows++;
        Coroutine::sleep(0.015);
        if ($this->onAcquire !== null) { ($this->onAcquire)(); }
        if ($this->failure instanceof \Throwable) { throw $this->failure; }
        return $this->failure === false ? null : $this->client;
    }
    public function recycleObj($obj): bool { $this->recycles++; return true; }
}

class AcquireTimingRetryPool extends Pool
{
    public int $created = 0;
    public function __construct(Config $config, private array $clients) { parent::__construct($config); }
    protected function createObject(): ObjectInterface
    {
        Coroutine::sleep(0.012);
        return $this->clients[$this->created++];
    }
}

class AcquireTimingBeginOverrideDb extends FastDb
{
    public int $begins = 0;
    public function begin(?Connection $client = null, TransactionStartFlags $flags = TransactionStartFlags::None, ?float $timeout = null): bool
    {
        $this->begins++;
        return parent::begin($client, $flags, $timeout);
    }
}
