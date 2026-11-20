<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;

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
    public function testFailedQueriesNotifyObserversAndPropagateTheirExceptions(bool $raw, bool $callbackThrows): void
    {
        $failure = new RuntimeException('original SQL failure');
        $observerFailure = new RuntimeException('logger failure');
        $method = $raw ? 'rawQuery' : 'query';
        $client = $this->createStub(Connection::class);
        $client->method($method)->willThrowException($failure);
        $db = new FastDb();
        (new ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [Coroutine::getCid() => ['default' => $client]]);
        $called = false;
        $db->setOnQuery(function (QueryResult $result) use (&$called, $callbackThrows, $failure, $observerFailure): void {
            $called = true;
            $this->assertNull($result->getResult());
            $this->assertSame($failure, $result->getException());
            $this->assertNull($result->getAffectedRows());
            $this->assertNull($result->getInsertId());
            $this->assertNull($result->getResultOne());
            if ($callbackThrows) {
                throw $observerFailure;
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
            $this->fail('Expected an exception');
        } catch (RuntimeException $exception) {
            $this->assertSame($callbackThrows ? $observerFailure : $failure, $exception);
        }
        $this->assertTrue($called);
    }

    public static function failedQueries(): array
    {
        return [[true, false], [false, false], [true, true], [false, true]];
    }

    public function testEntityCallbackFailurePropagatesDirectly(): void
    {
        $failure = new RuntimeException('original SQL failure');
        $observerFailure = new RuntimeException('entity logger failure');
        $client = $this->createStub(Connection::class);
        $client->method('query')->willThrowException($failure);
        $db = new FastDb();
        (new ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [Coroutine::getCid() => ['default' => $client]]);
        $instance = new ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        $called = false;
        try {
            $user = new User();
            $user->setOnQuery(function (QueryResult $result) use (&$called, $failure, $observerFailure): void {
                $called = true;
                $this->assertNull($result->getResult());
                $this->assertSame($failure, $result->getException());
                $this->assertNotNull($result->getLastPrepareQuery());
                throw $observerFailure;
            });
            try {
                $user->all();
                $this->fail('Expected observer exception');
            } catch (RuntimeException $exception) {
                $this->assertSame($observerFailure, $exception);
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
        (new ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [Coroutine::getCid() => ['default' => $client]]);
        $db->setOnQuery(function (): void { throw new RuntimeException('logger failure'); });
        $this->expectExceptionMessage('logger failure');
        $db->rawQuery('SELECT 1');
    }

    public function testBuilderFailureSkipsConnectionBorrowingAndQueryObservers(): void
    {
        $db = (new FastDb())->configureQueryStack(true);
        $db->setOnQuery(static function (): void { self::fail('No query was sent'); });
        $failure = new InvalidArgumentException('builder failed');
        try {
            $db->query(static function () use ($failure): void { throw $failure; });
            $this->fail('Expected builder failure');
        } catch (InvalidArgumentException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertNull($db->getQueryStack());
    }

    public function testBorrowFailureDoesNotNotifyQueryObservers(): void
    {
        $db = new FastDb();
        $db->setOnQuery(static function (): void { self::fail('Global observer requires SQL execution'); });
        $builder = (new QueryBuilder())->raw('SELECT ? AS never_sent', [1]);

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('connection unregistered not register yet');
        $db->query($builder, connection: 'unregistered',
            onQuery: static function (): void { self::fail('Local observer requires SQL execution'); });
    }
}
