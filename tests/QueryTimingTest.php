<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryTimingTest extends TestCase
{
    #[DataProvider('queryModes')]
    public function testDurationIncludesExecutionAndExcludesLogging(bool $raw, bool $fails): void
    {
        $failure = new \RuntimeException('SQL failure');
        $method = $raw ? 'rawQuery' : 'query';
        $client = $this->createStub(Connection::class);
        $client->method($method)->willReturnCallback(function () use ($fails, $failure) {
            usleep(20000);
            if ($fails) {
                throw $failure;
            }
            return [];
        });
        $db = (new FastDb())->isEnableQueryStack(true);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $logged = null;
        $db->setOnQuery(function (QueryResult $result) use (&$logged): void {
            $logged = $result;
            usleep(30000);
        });
        $start = microtime(true);
        try {
            if ($raw) {
                $db->rawQuery('SELECT 1');
            } else {
                $query = new QueryBuilder();
                $query->raw('SELECT 1');
                $db->query($query);
            }
            $this->assertFalse($fails);
        } catch (\RuntimeException $exception) {
            $this->assertTrue($fails);
            $this->assertSame($failure, $exception);
        }
        $wall = microtime(true) - $start;
        $this->assertInstanceOf(QueryResult::class, $logged);
        $this->assertSame($fails ? $failure : null, $logged->getException());
        $duration = $logged->getDuration();
        $this->assertGreaterThanOrEqual(0.018, $duration);
        $this->assertGreaterThanOrEqual(0.025, $wall - $duration);
        $stack = $db->getQueryStack(0);
        $this->assertSame($logged->getEndTime(), $stack->endTime);
        $this->assertSame($logged->getStartTime(), $stack->startTime);
        $this->assertSame($duration, $stack->duration);
    }

    public function testDurationIsIndependentOfWallClockAndFrozenAfterFinish(): void
    {
        $result = new QueryResult(-1000.0, hrtime(true) - 20_000_000);
        $result->finishTiming();
        $duration = $result->getDuration();
        $this->assertGreaterThanOrEqual(0.02, $duration);
        $this->assertLessThan(1.0, $duration);
        $result->setStartTime(1000.0);
        $result->setEndTime(-1000.0);
        usleep(1000);
        $this->assertSame($duration, $result->getDuration());
    }

    #[DataProvider('transactionModes')]
    public function testTransactionDurationIsRecorded(string $operation, bool $fails): void
    {
        $client = $this->createStub(Connection::class);
        $client->isInTransaction = $operation !== 'begin';
        $method = match ($operation) {
            'begin' => 'beginTransaction',
            'commit' => 'commitTransaction',
            'rollback' => 'rollbackTransaction',
        };
        $failure = new \RuntimeException('transaction failure');
        $client->method($method)->willReturnCallback(function () use ($fails, $failure) {
            usleep(20000);
            if ($fails) {
                throw $failure;
            }
            return true;
        });
        $db = (new FastDb())->isEnableQueryStack(true);
        $logged = null;
        $db->setOnQuery(function (QueryResult $result) use (&$logged): void {
            $logged = $result;
        });
        try {
            $this->assertTrue($db->$operation($client));
            $this->assertFalse($fails);
        } catch (\RuntimeException $error) {
            $this->assertTrue($fails);
            $this->assertSame($failure, $error);
        }
        $this->assertInstanceOf(QueryResult::class, $logged);
        $this->assertGreaterThanOrEqual(0.018, $logged->getDuration());
        $this->assertSame($logged->getDuration(), $db->getQueryStack(-1)->duration);
    }

    public static function transactionModes(): array
    {
        return [['begin', false], ['begin', true], ['commit', false], ['commit', true],
            ['rollback', false], ['rollback', true]];
    }

    public static function queryModes(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }
}
