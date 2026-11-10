<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Config as DriverConfig;
use EasySwoole\Pool\ObjectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class QueryStackTest extends TestCase
{
    private function database(int $capacity = 256): FastDb
    {
        $client = $this->createStub(Connection::class);
        $client->method('rawQuery')->willReturn([]);
        $db = (new FastDb())->isEnableQueryStack(true, $capacity);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        return $db;
    }

    private function sql(FastDb $db): ?array
    {
        $stack = $db->getQueryStack();
        return $stack === null ? null : array_map(static fn($entry): ?string => $entry->rawQuery, $stack);
    }

    private function pooledDatabase(int $capacity): FastDb
    {
        $config = new Config(['maxObjectNum' => 2, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $db = (new FastDb())->addDb($config)->isEnableQueryStack(true, $capacity);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => new QueryStackPool($config)]);
        return $db;
    }

    public function testNegativeIndexesCountFromLastQuery(): void
    {
        $db = $this->database();
        $db->rawQuery('SELECT 11');
        $db->rawQuery('SELECT 22');
        $db->rawQuery('SELECT 33');

        $this->assertSame('SELECT 33', $db->getQueryStack(-1)->rawQuery);
        $this->assertSame('SELECT 22', $db->getQueryStack(-2)->rawQuery);
        $this->assertSame('SELECT 11', $db->getQueryStack(-3)->rawQuery);
        $this->assertSame($db->getQueryStack(2), $db->getQueryStack(-1));
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-3));
        $this->assertCount(3, $db->getQueryStack());
        $this->assertNull($db->getQueryStack(-4));
        $this->assertNull($db->getQueryStack(3));
    }

    public function testOnlyQueryIsAvailableAtMinusOne(): void
    {
        $db = $this->database();
        $db->rawQuery('SELECT 1');
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-1));
        $this->assertSame('SELECT 1', $db->getQueryStack(-1)->rawQuery);
        $this->assertNull($db->getQueryStack(-2));
    }

    public function testMissingStackReturnsNull(): void
    {
        $db = $this->database();
        $this->assertNull($db->getQueryStack(-1));
        $this->assertNull($db->getQueryStack(0));
        $this->assertNull($db->getQueryStack());
    }

    public function testTheDefaultCapacityKeepsOnlyTheLatest256Queries(): void
    {
        $db = $this->database();
        for ($i = 0; $i < 300; $i++) { $db->rawQuery('SELECT ' . $i); }
        $this->assertCount(256, $db->getQueryStack());
        $this->assertSame(range(0, 255), array_keys($db->getQueryStack()));
        $this->assertSame('SELECT 44', $db->getQueryStack(0)->rawQuery);
        $this->assertSame('SELECT 299', $db->getQueryStack(-1)->rawQuery);
        $this->assertNull($db->getQueryStack(-257));
        $this->assertNull($db->getQueryStack(256));
    }

    public function testWrappingPreservesChronologicalIndexesAndDoesNotChangeReturnedSnapshots(): void
    {
        $db = $this->database(3);
        foreach ([1, 2, 3, 4] as $i) { $db->rawQuery('SELECT ' . $i); }
        $before = $db->getQueryStack();
        foreach ([5, 6, 7, 8] as $i) { $db->rawQuery('SELECT ' . $i); }
        $this->assertSame(['SELECT 6', 'SELECT 7', 'SELECT 8'], $this->sql($db));
        $this->assertSame([0, 1, 2], array_keys($db->getQueryStack()));
        $this->assertSame('SELECT 6', $db->getQueryStack(0)->rawQuery);
        $this->assertSame($db->getQueryStack(2), $db->getQueryStack(-1));
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-3));
        $this->assertNull($db->getQueryStack(-4));
        $this->assertSame(['SELECT 2', 'SELECT 3', 'SELECT 4'], array_map(static fn($entry) => $entry->rawQuery, $before));
    }

    public function testCapacityOneAndRepeatedResizeKeepTheLatestQueries(): void
    {
        $db = $this->database(3);
        for ($i = 0; $i < 5; $i++) { $db->rawQuery('SELECT ' . $i); }
        $this->assertSame($db, $db->isEnableQueryStack(true, 2));
        $this->assertSame(['SELECT 3', 'SELECT 4'], $this->sql($db));
        $db->isEnableQueryStack(true, 4);
        foreach ([5, 6, 7] as $i) { $db->rawQuery('SELECT ' . $i); }
        $this->assertSame(['SELECT 4', 'SELECT 5', 'SELECT 6', 'SELECT 7'], $this->sql($db));
        $db->isEnableQueryStack(true, 1);
        $this->assertSame(['SELECT 7'], $this->sql($db));
        $db->rawQuery('SELECT 8');
        $this->assertSame(['SELECT 8'], $this->sql($db));
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-1));
        $this->assertNull($db->getQueryStack(1));
        $db->isEnableQueryStack(true, 3);
        $db->rawQuery('SELECT 9');
        $this->assertSame(['SELECT 8', 'SELECT 9'], $this->sql($db));
    }

    public function testEvictionReleasesRecordsThatNoCallerRetains(): void
    {
        $db = $this->database(2);
        $db->rawQuery('SELECT 1');
        $oldest = \WeakReference::create($db->getQueryStack(0));
        $db->rawQuery('SELECT 2');
        $this->assertNotNull($oldest->get());
        $db->rawQuery('SELECT 3');
        $this->assertNull($oldest->get());
        $this->assertSame(['SELECT 2', 'SELECT 3'], $this->sql($db));
    }

    public static function invalidCapacities(): array { return [[0], [-1], [PHP_INT_MIN]]; }

    #[DataProvider('invalidCapacities')]
    public function testInvalidCapacityChangesNeitherTheEnabledFlagNorTheCapacity(int $capacity): void
    {
        $db = $this->database(2);
        foreach ([1, 2, 3] as $i) { $db->rawQuery('SELECT ' . $i); }
        $snapshot = $db->getQueryStack();
        try { $db->isEnableQueryStack(false, $capacity); $this->fail('A non-positive capacity must be rejected'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame($snapshot, $db->getQueryStack());
        $db->rawQuery('SELECT 4');
        $this->assertSame(['SELECT 3', 'SELECT 4'], $this->sql($db));
        $db->isEnableQueryStack(false, 2);
        try { $db->isEnableQueryStack(true, $capacity); $this->fail('An invalid request cannot enable logging'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $db->rawQuery('SELECT 5');
        $this->assertSame(['SELECT 3', 'SELECT 4'], $this->sql($db));
    }

    public function testDisablingPreservesRecordsAndResumingUsesTheConfiguredCapacity(): void
    {
        $db = $this->database(2);
        foreach ([1, 2, 3] as $i) { $db->rawQuery('SELECT ' . $i); }
        $snapshot = $db->getQueryStack();
        $this->assertSame($db, $db->isEnableQueryStack(false, 2));
        $db->rawQuery('SELECT 4');
        $this->assertSame($snapshot, $db->getQueryStack());
        $db->isEnableQueryStack(true, 2);
        $db->rawQuery('SELECT 5');
        $this->assertSame(['SELECT 3', 'SELECT 5'], $this->sql($db));
    }

    public function testDisablingWithoutACapacityAppliesTheDefaultLimitAndKeepsRecentRecords(): void
    {
        $db = $this->database(300);
        for ($i = 0; $i < 300; $i++) { $db->rawQuery('SELECT ' . $i); }
        $db->isEnableQueryStack(false);
        $this->assertCount(256, $db->getQueryStack());
        $this->assertSame('SELECT 44', $db->getQueryStack(0)->rawQuery);
        $this->assertSame('SELECT 299', $db->getQueryStack(-1)->rawQuery);
        $db->rawQuery('SELECT 300');
        $this->assertSame('SELECT 299', $db->getQueryStack(-1)->rawQuery);
        $db->isEnableQueryStack(false, 1);
        $this->assertSame(['SELECT 299'], $this->sql($db));
    }

    public function testCapacityIsPerFastDbInstance(): void
    {
        $small = $this->database(1);
        $large = $this->database(3);
        foreach ([1, 2, 3, 4] as $i) { $small->rawQuery('SELECT ' . $i); $large->rawQuery('SELECT ' . $i); }
        $this->assertSame(['SELECT 4'], $this->sql($small));
        $this->assertSame(['SELECT 2', 'SELECT 3', 'SELECT 4'], $this->sql($large));
    }

    public function testAcquireFailuresAndQueriesShareOneLimitAndDisabledAcquisitionsDoNotAppend(): void
    {
        $client = $this->createStub(Connection::class);
        $client->method('rawQuery')->willReturn([]);
        $pool = $this->createStub(Pool::class);
        $pool->method('getObj')->willReturnOnConsecutiveCalls(null, null, null, null, $client);
        $db = (new FastDb())->addDb(new Config())->isEnableQueryStack(true, 2);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => $pool]);
        $errors = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                try { $db->rawQuery('SELECT never_sent'); $this->fail('Fixture acquisition must fail'); }
                catch (RuntimeError $error) { $errors[] = $error; }
            }
            $this->assertSame(['connectionAcquire', 'connectionAcquire'], array_column($db->getQueryStack(), 'type'));
            $this->assertSame($errors[1], $db->getQueryStack(0)->exception);
            $this->assertSame($errors[2], $db->getQueryStack(-1)->exception);
            $snapshot = $db->getQueryStack();
            $db->isEnableQueryStack(false, 2);
            try { $db->rawQuery('SELECT still_not_sent'); $this->fail('The disabled acquisition also fails'); }
            catch (RuntimeError) { $this->addToAssertionCount(1); }
            $this->assertSame($snapshot, $db->getQueryStack());
            $db->isEnableQueryStack(true, 2);
            $db->rawQuery('SELECT 1');
            $this->assertSame(['connectionAcquire', 'query'], array_column($db->getQueryStack(), 'type'));
            $this->assertNull($db->getQueryStack(0)->exception);
            $this->assertSame('SELECT 1', $db->getQueryStack(-1)->rawQuery);
            $db->rawQuery('SELECT 2');
            $this->assertSame(['SELECT 1', 'SELECT 2'], $this->sql($db));
        } finally { $db->recycleContext(false); }
    }

    public function testRecyclingWithoutABorrowedClientClearsWrappedAcquireFailures(): void
    {
        $pool = $this->createStub(Pool::class);
        $pool->method('getObj')->willReturn(null);
        $db = (new FastDb())->addDb(new Config())->isEnableQueryStack(true, 2);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['default' => $pool]);
        try {
            for ($i = 0; $i < 3; $i++) {
                try { $db->rawQuery('SELECT never_sent'); $this->fail('Fixture acquisition must fail'); }
                catch (RuntimeError) { /* 每次获取连接失败都会占用一个诊断记录位置。 */ }
            }
            $this->assertNull($db->currentConnection());
            $this->assertCount(2, $db->getQueryStack());
            $db->recycleContext();
            $this->assertNull($db->getQueryStack());
            $this->assertNull($db->getQueryStack(-1));
        } finally { $db->recycleContext(false); }
    }

    public function testRecyclingDropsTheWrappedStackAndStartsTheNextBorrowingAtZero(): void
    {
        $db = $this->pooledDatabase(2);
        try {
            foreach ([1, 2, 3] as $i) { $db->rawQuery('SELECT ' . $i); }
            $db->recycleContext();
            $this->assertNull($db->getQueryStack());
            $this->assertNull($db->getQueryStack(-1));
            $db->rawQuery('SELECT 4');
            $this->assertSame([null, 'SELECT 4'], $this->sql($db));
            $this->assertSame(['connectionAcquire', 'query'], array_column($db->getQueryStack(), 'type'));
            $this->assertSame([0, 1], array_keys($db->getQueryStack()));
            $db->rawQuery('SELECT 5');
            $this->assertSame(['SELECT 4', 'SELECT 5'], $this->sql($db));
        } finally { $db->recycleContext(false); $db->reset(); }
    }

    public function testResizingAppliesImmediatelyToEveryCoroutineAndExitReleasesItsStack(): void
    {
        $db = $this->pooledDatabase(3);
        $ready = new Coroutine\Channel(2);
        $resume = new Coroutine\Channel(2);
        $finished = new Coroutine\Channel(2);
        $results = [];
        $entries = [];
        try {
            foreach (['a', 'b'] as $name) {
                Coroutine::create(function () use ($db, $name, $ready, $resume, $finished, &$results, &$entries): void {
                    Coroutine::defer(static fn() => $finished->push($name));
                    try {
                        foreach ([1, 2, 3, 4] as $i) { $db->rawQuery('SELECT ' . $name . $i); }
                        $ready->push($name);
                        $resume->pop(2);
                        $results[$name] = $this->sql($db);
                        $db->rawQuery('SELECT ' . $name . '5');
                        $results[$name . '-next'] = $this->sql($db);
                        $entries[$name] = \WeakReference::create($db->getQueryStack(-1));
                    } catch (\Throwable $error) { $results[$name] = $error; }
                });
            }
            $this->assertSame('a', $ready->pop(2));
            $this->assertSame('b', $ready->pop(2));
            $this->assertNull($db->getQueryStack(), 'The parent coroutine cannot see either child stack.');
            $db->isEnableQueryStack(true, 2);
            // 后续调大容量不能恢复已经裁掉的记录。
            $db->isEnableQueryStack(true, 4);
            $resume->push(true);
            $resume->push(true);
            $this->assertSame('a', $finished->pop(2));
            $this->assertSame('b', $finished->pop(2));
            foreach (['a', 'b'] as $name) {
                $this->assertSame(['SELECT ' . $name . '3', 'SELECT ' . $name . '4'], $results[$name]);
                $this->assertSame(['SELECT ' . $name . '3', 'SELECT ' . $name . '4', 'SELECT ' . $name . '5'], $results[$name . '-next']);
                $this->assertNull($entries[$name]->get(), 'Coroutine exit must release its diagnostic events.');
            }
        } finally { $db->reset(); }
    }
}

class QueryStackConnection extends Connection
{
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->lastAffectRows = $this->lastInsertId = 0;
        return [];
    }
    public function beforeUse(): bool { return true; }
    public function intervalCheck(): bool { return true; }
}

class QueryStackPool extends Pool
{
    protected function createObject(): ObjectInterface { return new QueryStackConnection(new DriverConfig()); }
}
