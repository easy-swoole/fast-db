<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryFailureTest extends TestCase
{
    public function testUnassignedResultIsSafeToRead(): void
    {
        $result = new QueryResult(0.0, 0.0, 0.0, 'default');
        $this->assertNull($result->getResult());
        $this->assertNull($result->getResultOne());
        $this->assertNull($result->getException());
    }

    #[DataProvider('failedQueries')]
    public function testFailureCallbacksDoNotReplaceQueryException(bool $raw, bool $callbackThrows): void
    {
        $failure = new \RuntimeException('original SQL failure');
        $method = $raw ? 'rawQuery' : 'query';
        $client = $this->createStub(Connection::class);
        $client->method($method)->willThrowException($failure);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $called = false;
        $db->setOnQuery(function (QueryResult $result) use (&$called, $callbackThrows, $failure): void {
            $called = true;
            $this->assertNull($result->getResult());
            $this->assertSame($failure, $result->getException());
            $this->assertNull($result->getAffectedRows());
            $this->assertNull($result->getInsertId());
            $this->assertNull($result->getResultOne());
            if ($callbackThrows) {
                throw new \RuntimeException('logger failure');
            }
        });
        try {
            if ($raw) {
                $db->rawQuery('SELECT invalid');
            } else {
                $query = new QueryBuilder();
                $query->raw('SELECT invalid');
                $db->query($query);
            }
            $this->fail('Expected original SQL exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
        $this->assertTrue($called);
    }

    public static function failedQueries(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }

    public function testEntityCallbackAlsoPreservesOriginalFailure(): void
    {
        $failure = new \RuntimeException('original SQL failure');
        $client = $this->createStub(Connection::class);
        $client->method('query')->willThrowException($failure);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        $called = false;
        try {
            $user = new User();
            $user->setOnQuery(function (QueryResult $result) use (&$called, $failure): void {
                $called = true;
                $this->assertNull($result->getResult());
                $this->assertSame($failure, $result->getException());
                $this->assertNotNull($result->getLastPrepareQuery());
                throw new \RuntimeException('entity logger failure');
            });
            try {
                $user->all();
                $this->fail('Expected original SQL exception');
            } catch (\RuntimeException $exception) {
                $this->assertSame($failure, $exception);
            }
            $this->assertTrue($called);
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testSuccessfulQueryStillPropagatesCallbackFailure(): void
    {
        $client = $this->createStub(Connection::class);
        $client->method('rawQuery')->willReturn([]);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $db->setOnQuery(function (): void { throw new \RuntimeException('logger failure'); });
        $this->expectExceptionMessage('logger failure');
        $db->rawQuery('SELECT 1');
    }

    public function testBorrowFailureKeepsTheOriginalQueryAfterBuilderReuse(): void
    {
        $db = new FastDb();
        $builder = (new QueryBuilder())->raw('SELECT ? AS original_query', [1]);
        $failureResult = null;
        try {
            $db->query($builder, connection: 'unregistered',
                onQuery: static function (QueryResult $result) use (&$failureResult): void {
                    $failureResult = $result;
                });
            $this->fail('Expected an unregistered connection error');
        } catch (\EasySwoole\FastDb\Exception\RuntimeError $error) {
            $this->assertSame($error, $failureResult->getException());
        }
        $builder->raw('SELECT ? AS next_query', [2]);

        $this->assertSame('SELECT ? AS original_query', $failureResult->getLastPrepareQuery());
        $this->assertSame([1], $failureResult->getLastBindParams());
    }
}
