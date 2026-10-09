<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function testRestoreAllowsStartingANewTransaction(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['rollbackTransaction', 'beginTransaction'])
            ->getMock();
        $connection->expects($this->once())->method('rollbackTransaction')->willReturn(true);
        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->isInTransaction = true;

        $connection->objectRestore();

        $this->assertFalse($connection->isInTransaction);
        $this->assertTrue((new FastDb())->begin($connection));
        $this->assertTrue($connection->isInTransaction);
    }

    public function testForceRollbackRemainsEnabledAcrossRestores(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rollbackTransaction'])->getMock();
        $connection->expects($this->exactly(2))->method('rollbackTransaction')->willReturn(true);
        $connection->isForceRollback = true;
        $connection->isInTransaction = true;

        $connection->objectRestore();
        $connection->objectRestore();

        $this->assertFalse($connection->isInTransaction);
        $this->assertTrue($connection->isForceRollback);
    }

    public function testFailedRollbackRejectsRestore(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rollbackTransaction'])->getMock();
        $connection->expects($this->once())->method('rollbackTransaction')->willReturn(false);
        $connection->isInTransaction = true;

        $this->expectException(RuntimeError::class);
        $connection->objectRestore();
    }

    public function testRollbackExceptionIsPropagated(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rollbackTransaction'])->getMock();
        $connection->expects($this->once())->method('rollbackTransaction')
            ->willThrowException(new \RuntimeException('rollback failed'));
        $connection->isInTransaction = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback failed');
        $connection->objectRestore();
    }

    public function testRollbackBaselinesDoNotKeepEntitiesAlive(): void
    {
        $connection = new Connection(new \EasySwoole\Mysqli\Config());
        $connection->isInTransaction = true;
        $entity = new \stdClass();
        $weak = \WeakReference::create($entity);
        $restored = false;
        $connection->rememberTransactionBaseline($entity, [], static function () use (&$restored): void {
            $restored = true;
        });
        unset($entity);
        $this->assertNull($weak->get());
        $connection->finishTransaction(false);
        $this->assertFalse($restored);
    }

    public function testFailedRollbackKeepsBaselinesForLaterSuccessfulRestore(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rollbackTransaction'])->getMock();
        $connection->expects($this->exactly(2))->method('rollbackTransaction')->willReturnOnConsecutiveCalls(false, true);
        $connection->isInTransaction = true;
        $entity = new \stdClass();
        $entity->value = 1;
        $connection->rememberTransactionBaseline($entity, ['value' => 0], static function ($entity, $baseline): void {
            $entity->value = $baseline['value'];
        });
        try {
            $connection->objectRestore();
            $this->fail('Expected failed rollback');
        } catch (RuntimeError $error) {
            $this->assertSame('Failed to rollback connection during restore', $error->getMessage());
        }
        $this->assertTrue($connection->isInTransaction);
        $this->assertSame(1, $entity->value);
        $connection->objectRestore();
        $this->assertFalse($connection->isInTransaction);
        $this->assertSame(0, $entity->value);
    }


    public function testDiscardedConnectionRestoresBaselinesAfterClose(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rollbackTransaction', 'close'])->getMock();
        $connection->expects($this->once())->method('rollbackTransaction')->willReturn(false);
        $connection->expects($this->once())->method('close')->willReturn(true);
        $connection->isInTransaction = true;
        $entity = new \stdClass();
        $entity->value = 1;
        $connection->rememberTransactionBaseline($entity, ['value' => 0], static function ($entity, $baseline): void {
            $entity->value = $baseline['value'];
        });
        $connection->gc();
        $this->assertFalse($connection->isInTransaction);
        $this->assertSame(0, $entity->value);
    }

    public function testQueryTimeoutReachesMysqliClient(): void
    {
        $connection = new Connection(new \EasySwoole\Mysqli\Config());
        $builder = new \EasySwoole\Mysqli\QueryBuilder();
        $builder->raw('SELECT 1');
        $this->expectException(\InvalidArgumentException::class);
        $connection->query($builder, 0.0);
    }

    public function testRawQueryTimeoutReachesMysqliClient(): void
    {
        $connection = new Connection(new \EasySwoole\Mysqli\Config());
        $this->expectException(\InvalidArgumentException::class);
        $connection->rawQuery('SELECT 1', 0.0);
    }

    public function testEnumTransactionFlagsPreserveChainState(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['beginTransaction', 'commitTransaction'])->getMock();
        $connection->expects($this->once())->method('beginTransaction')
            ->with(\EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly)->willReturn(true);
        $connection->expects($this->once())->method('commitTransaction')
            ->with(\EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::Chain)->willReturn(true);
        $db = new FastDb();
        $this->assertTrue($db->begin($connection, \EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly));
        $this->assertTrue($db->commit($connection, \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::Chain));
        $this->assertTrue($connection->isInTransaction);
        $connection->finishTransaction(true);
    }

    public function testMysqliConfigReceivesTimeoutSettings(): void
    {
        $config = new \EasySwoole\FastDb\Config(['timeout' => 7, 'maxConnectTime' => 8, 'compress' => true]);
        $driver = new \EasySwoole\Mysqli\Config($config->toArray());
        $this->assertSame(7.0, $driver->getTimeout());
        $this->assertSame(8.0, $driver->getMaxConnectTime());
        $this->assertTrue($driver->isCompress());
        $config->setMaxConnectTime(9);
        $this->assertSame(9.0, (new \EasySwoole\Mysqli\Config($config->toArray()))->getMaxConnectTime());
    }

    public function testTestDbPreservesConnectionFailure(): void
    {
        $db = (new FastDb())->addDb(new \EasySwoole\FastDb\Config(['maxConnectTime' => -1.0]));
        try {
            $db->testDb();
            $this->fail('Expected connection failure');
        } catch (RuntimeError $error) {
            $this->assertInstanceOf(\InvalidArgumentException::class, $error->getPrevious());
            $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
        }
    }

    public function testTransactionFlagsAndTimeoutReachSpecifiedConnection(): void
    {
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $flags = $operation === 'begin'
                ? \EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly
                : \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::NoChainNoRelease;
            $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
                ->onlyMethods([$operation . 'Transaction'])->getMock();
            $connection->isInTransaction = $operation !== 'begin';
            $connection->expects($this->once())->method($operation . 'Transaction')
                ->with($flags, 0.05)->willReturn(true);
            $this->assertTrue((new FastDb())->$operation(client: $connection, flags: $flags, timeout: 0.05));
            $this->assertSame($operation === 'begin', $connection->isInTransaction);
        }
    }

    public function testTransactionTimeoutBoundsAnUnresponsiveConnection(): void
    {
        $check = function (): void {
            foreach (['begin', 'commit', 'rollback'] as $operation) {
                [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
                $config = new \EasySwoole\Mysqli\Config(['timeout' => 2.0]);
                $protocol = new \EasySwoole\Mysqli\Protocol\Connection($config);
                (new \ReflectionProperty($protocol, 'socket'))->setValue($protocol, $socket);
                (new \ReflectionProperty($protocol, 'connected'))->setValue($protocol, true);
                (new \ReflectionProperty($protocol, 'inTransaction'))->setValue($protocol, $operation !== 'begin');
                $connection = new Connection($config);
                (new \ReflectionProperty(\EasySwoole\Mysqli\Client::class, 'mysqlClient'))->setValue($connection, $protocol);
                $connection->isInTransaction = $operation !== 'begin';
                $flags = $operation === 'begin'
                    ? \EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly
                    : \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::NoChainNoRelease;
                $started = microtime(true);
                try {
                    (new FastDb())->$operation($connection, $flags, 0.03);
                    $this->fail('Expected transaction timeout');
                } catch (\EasySwoole\Mysqli\Exception\TimeoutException $error) {
                    $elapsed = microtime(true) - $started;
                    $this->assertGreaterThanOrEqual(0.015, $elapsed);
                    $this->assertLessThan(0.5, $elapsed);
                    $this->assertFalse($protocol->isConnected());
                    $this->assertSame($operation !== 'begin', $connection->isInTransaction);
                    $expected = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
                    $packet = $peer->recvAll(5 + strlen($expected), 1.0);
                    $this->assertSame("\x03" . $expected, substr($packet, 4));
                } finally {
                    $connection->close();
                    $peer->close();
                }
            }
        };
        if (\Swoole\Coroutine::getCid() < 0) {
            \Swoole\Coroutine\run($check);
        } else {
            $check();
        }
    }

    public function testReleaseFlagClosesConnectionOnlyAfterSuccessfulCommit(): void
    {
        $flags = \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::Release;
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['rawQuery', 'close'])->getMock();
        $connection->expects($this->once())->method('rawQuery')->with('COMMIT RELEASE', 0.05)->willReturn(true);
        $connection->expects($this->once())->method('close')->willReturn(true);
        $this->assertTrue($connection->commitTransaction($flags, 0.05));
    }

    public function testTransactionFlagsRejectIntegersAndWrongEnumTypes(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['beginTransaction', 'commitTransaction', 'rollbackTransaction'])->getMock();
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $connection->expects($this->never())->method($operation . 'Transaction');
        }
        $connection->isInTransaction = true;
        $db = new FastDb();
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $wrongEnum = $operation === 'begin'
                ? \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::None
                : \EasySwoole\Mysqli\Transaction\TransactionStartFlags::None;
            foreach ([0, 1, $wrongEnum] as $flags) {
                try {
                    $db->$operation($connection, $flags, 0.05);
                    $this->fail('Expected invalid transaction flags to be rejected');
                } catch (\TypeError $error) {
                    $this->assertStringContainsString('flags', $error->getMessage());
                }
            }
        }
    }

    public function testTransactionLogsMatchExecutedSqlForEveryFlag(): void
    {
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $cases = $operation === 'begin'
                ? \EasySwoole\Mysqli\Transaction\TransactionStartFlags::cases()
                : \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::cases();
            foreach ($cases as $flags) {
                $expected = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
                $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
                    ->onlyMethods(['rawQuery', 'close'])->getMock();
                $connection->isInTransaction = $operation !== 'begin';
                $connection->expects($this->once())->method('rawQuery')->with($expected, 0.05)->willReturn(true);
                $connection->method('close')->willReturn(true);
                $db = (new FastDb())->isEnableQueryStack(true);
                $observed = null;
                $db->setOnQuery(static function (\EasySwoole\FastDb\Mysql\QueryResult $result) use (&$observed): void {
                    $observed = $result;
                });
                $this->assertTrue($db->$operation($connection, $flags, 0.05));
                $this->assertSame($expected, $observed->getRawSql());
                $this->assertSame($connection, $observed->getConnection());
                $this->assertSame($expected, $db->getQueryStack(-1)->rawQuery);
                $connection->finishTransaction(true);
            }
        }
    }

}
