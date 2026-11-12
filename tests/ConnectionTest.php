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
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $this->queueOk($peer);
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
            $this->queueOk($peer, true);
            $this->assertTrue((new FastDb())->begin($connection));
            $this->assertTrue($connection->isInTransaction());
        }, true);
    }

    public function testForceRollbackRemainsEnabledAcrossRestores(): void
    {
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $connection->isForceRollback = true;
            $this->queueOk($peer); $this->queueOk($peer);
            $connection->objectRestore(); $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
            $this->assertTrue($connection->isForceRollback);
        }, true);
    }

    public static function cleanupMethods(): array { return [['objectRestore'], ['gc']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('cleanupMethods')]
    public function testForceRollbackDoesNotOpenAClosedSession(string $method): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([new \EasySwoole\Mysqli\Config()])
            ->onlyMethods(['rawQuery'])->getMock();
        $connection->isForceRollback = true;
        $connection->close();
        $connection->expects($this->never())->method('rawQuery');

        $connection->$method();

        $this->assertNull($connection->mysqlClient());
    }

    public function testRestoreRollsBackTheActiveSession(): void
    {
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $this->queueOk($peer);
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
            $this->assertNotNull($connection->mysqlClient());
            $command = $peer->recvAll(5 + strlen('ROLLBACK AND NO CHAIN NO RELEASE'), 0.1);
            $this->assertSame("\x03ROLLBACK AND NO CHAIN NO RELEASE", substr($command, 4));
        }, true);
    }

    public function testFailedRollbackRejectsRestore(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rawQuery', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method('rawQuery')->willReturn(false);
        $connection->method('isInTransaction')->willReturn(true);
        $this->expectException(RuntimeError::class);
        $connection->objectRestore();
    }

    public function testRollbackExceptionIsPropagated(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['rawQuery', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method('rawQuery')
            ->willThrowException(new \RuntimeException('rollback failed'));
        $connection->method('isInTransaction')->willReturn(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rollback failed');
        $connection->objectRestore();
    }

    public function testFailedRollbackKeepsSessionForLaterSuccessfulRestore(): void
    {
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $payload = "\xff" . pack('v', 1064) . '#42000rollback failed';
            $this->queuePacket($peer, $payload); $this->queueOk($peer);
            try { $connection->objectRestore(); $this->fail('Expected rollback SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); }
            $this->assertTrue($connection->isInTransaction());
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    public function testDiscardedConnectionClosesWithoutSendingRollback(): void
    {
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $connection->isForceRollback = true;
            $connection->gc();
            $this->assertFalse($connection->isInTransaction());
            $this->assertNull($connection->mysqlClient());
            $this->assertSame("\x01\0\0\0\x01", $peer->recvAll(5, 0.1));
        }, true);
    }

    public function testForceRollbackAccessorUsesTheExistingConfigurationKey(): void
    {
        $config = new \EasySwoole\FastDb\Config(['isForceRollback' => true]);
        $this->assertTrue($config->isForceRollback());
        $config->setForceRollback(false);
        $this->assertFalse($config->toArray()['isForceRollback']);
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
        $this->withConnected(function (Connection $connection, \Swoole\Coroutine\Socket $peer): void {
            $this->queueOk($peer, true); $this->queueOk($peer, true);
            $db = new FastDb();
            $this->assertTrue($db->begin($connection, \EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly));
            $this->assertTrue($db->commit($connection, \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::Chain));
            $this->assertTrue($connection->isInTransaction());
            $this->assertTrue($connection->mysqlClient()->inTransaction());
        });
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
                ->onlyMethods(['rawQuery', 'isInTransaction'])->getMock();
            $active = $operation !== 'begin';
            $connection->method('isInTransaction')->willReturnCallback(static function () use (&$active): bool { return $active; });
            $sql = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
            $connection->expects($this->once())->method('rawQuery')->with($sql, 0.05)->willReturnCallback(
                static function () use (&$active, $operation): bool { $active = $operation === 'begin'; return true; }
            );
            $this->assertTrue((new FastDb())->$operation(client: $connection, flags: $flags, timeout: 0.05));
            $this->assertSame($operation === 'begin', $connection->isInTransaction());
        }
    }

    public function testTransactionTimeoutBoundsAnUnresponsiveConnection(): void
    {
        $check = function (): void {
            foreach (['begin', 'commit', 'rollback'] as $operation) {
                [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
                $config = new \EasySwoole\Mysqli\Config(['timeout' => 2.0]);
                $protocol = $this->createConnectedProtocol($config, $socket, $operation !== 'begin');
                $connection = new Connection($config);
                (new \ReflectionProperty(\EasySwoole\Mysqli\Client::class, 'mysqlClient'))->setValue($connection, $protocol);
                $flags = $operation === 'begin'
                    ? \EasySwoole\Mysqli\Transaction\TransactionStartFlags::ReadOnly
                    : \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::NoChainNoRelease;
                $started = microtime(true);
                try {
                    (new FastDb())->$operation($connection, $flags, 0.03);
                    $this->fail('Expected transaction timeout');
                } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
                    $elapsed = microtime(true) - $started;
                    $this->assertGreaterThanOrEqual(0.015, $elapsed);
                    $this->assertLessThan(0.5, $elapsed);
                    $this->assertFalse($protocol->isConnected());
                    $this->assertFalse($connection->isInTransaction());
                    $this->assertInstanceOf(\EasySwoole\Mysqli\Exception\TimeoutException::class, $error->getPrevious());
                    $this->assertSame($error->getPrevious()->getCode(), $error->getCode());
                    $expected = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
                    $this->assertSame($expected, $error->getRawSql());
                    $this->assertNull($error->getQueryBuilder());
                    $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
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
            ->onlyMethods(['rawQuery', 'close', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method('rawQuery')->with('COMMIT RELEASE', 0.05)->willReturn(true);
        $connection->expects($this->once())->method('close')->willReturn(true);
        $this->assertTrue($connection->commitTransaction($flags, 0.05));
    }

    public function testTransactionFlagsRejectIntegersAndWrongEnumTypes(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['beginTransaction', 'commitTransaction', 'rollbackTransaction', 'isInTransaction'])->getMock();
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $connection->expects($this->never())->method($operation . 'Transaction');
        }
        $connection->method('isInTransaction')->willReturn(true);
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
                    ->onlyMethods(['rawQuery', 'close', 'isInTransaction'])->getMock();
                $connection->method('isInTransaction')->willReturn($operation !== 'begin');
                $connection->expects($this->once())->method('rawQuery')->with($expected, 0.05)->willReturn(true);
                $connection->method('close')->willReturn(true);
                $db = (new FastDb())->configureQueryStack(true);
                $observed = null;
                $db->setOnQuery(static function (\EasySwoole\FastDb\Mysql\QueryResult $result) use (&$observed): void {
                    $observed = $result;
                });
                $this->assertTrue($db->$operation($connection, $flags, 0.05));
                $this->assertSame($expected, $observed->getRawSql());
                $this->assertNull($observed->getAffectedRows());
                $this->assertSame($expected, $db->getQueryStack(-1)->rawQuery);
            }
        }
    }

    public function testUnbuiltQueryKeepsOriginalInvalidTimeoutException(): void
    {
        $connection = new Connection(new \EasySwoole\Mysqli\Config());
        $this->expectException(\InvalidArgumentException::class);
        $connection->query(new \EasySwoole\Mysqli\QueryBuilder(), 0.0);
    }

    public function testConfiguredTimeoutIsReportedWithoutExplicitOverride(): void
    {
        $check = function (): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new \EasySwoole\Mysqli\Config(['timeout' => 0.03]);
            $protocol = $this->createConnectedProtocol($config, $socket);
            $connection = new Connection($config);
            $connection->connectionName = 'reporting';
            (new \ReflectionProperty(\EasySwoole\Mysqli\Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            try {
                $connection->rawQuery('SELECT 1');
                $this->fail('Expected timeout');
            } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
                $this->assertSame('SELECT 1', $error->getRawSql());
                $this->assertNull($error->getQueryBuilder());
                $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
                $this->assertSame($error->getPrevious()->getCode(), $error->getCode());
            } finally { $connection->close(); $peer->close(); }
        };
        if (\Swoole\Coroutine::getCid() < 0) { \Swoole\Coroutine\run($check); }
        else { $check(); }
    }

    public function testTimeoutIsCaughtByFastDbBaseException(): void
    {
        $cause = new \EasySwoole\Mysqli\Exception\TimeoutException('query timed out', 110);
        $error = new \EasySwoole\FastDb\Exception\TimeoutException($cause->getMessage(), 110, $cause);
        $error->rawSql = 'SELECT 1';
        try {
            throw $error;
        } catch (\EasySwoole\FastDb\Exception\Exception $caught) {
            $this->assertSame($error, $caught);
            $this->assertSame($cause, $caught->getPrevious());
            $this->assertSame('SELECT 1', $caught->getRawSql());
            $this->assertSame(110, $caught->getCode());
        }
    }

    public function testTimeoutContextUsesObjectPropertyAssignment(): void
    {
        $error = new \EasySwoole\FastDb\Exception\TimeoutException('query timed out');
        $this->assertNull($error->getRawSql());
        $this->assertNull($error->getQueryBuilder());
        $builder = new \EasySwoole\Mysqli\QueryBuilder();
        $builder->raw('SELECT ?', [1]);
        $error->rawSql = 'SELECT ?';
        $error->queryBuilder = $builder;
        $this->assertSame('SELECT ?', $error->getRawSql());
        $this->assertSame($builder, $error->getQueryBuilder());
    }

    public static function failedTransactionModes(): array
    {
        return [['begin', false], ['begin', true], ['commit', false], ['commit', true], ['rollback', false], ['rollback', true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failedTransactionModes')]
    public function testFailedTransactionCallbackKeepsOriginalError(string $operation, bool $loggerThrows): void
    {
        $failure = new \RuntimeException('transaction execution failed');
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods([$operation . 'Transaction', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method($operation . 'Transaction')->willThrowException($failure);
        $connection->method('isInTransaction')->willReturn($operation !== 'begin');
        $db = (new FastDb())->configureQueryStack(true);
        $called = 0;
        $expected = $operation === 'begin' ? 'START TRANSACTION' : strtoupper($operation) . ' AND NO CHAIN NO RELEASE';
        $db->setOnQuery(function (\EasySwoole\FastDb\Mysql\QueryResult $result) use ($failure, $connection, $expected, $loggerThrows, &$called): void {
            $called++;
            $this->assertSame($failure, $result->getException());
            $this->assertSame($expected, $result->getRawSql());
            $this->assertNull($result->getAffectedRows());
            $this->assertNull($result->getResult());
            if ($loggerThrows) { throw new \RuntimeException('logger failed'); }
        });
        try {
            $db->$operation($connection);
            $this->fail('Expected transaction error');
        } catch (\RuntimeException $error) {
            $this->assertSame($failure, $error);
        }
        $this->assertSame(1, $called);
        $this->assertSame($expected, $db->getQueryStack(-1)->rawQuery);
        $this->assertSame($operation !== 'begin', $connection->isInTransaction());
    }


    /** 使用已连接的真实协议 Socket，测试不依赖 MySQL 服务。 */
    private function withConnected(callable $test, bool $inTransaction = false): void
    {
        [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
        $config = new \EasySwoole\Mysqli\Config(['timeout' => 0.03]);
        $protocol = $this->createConnectedProtocol($config, $socket, $inTransaction);
        $connection = new Connection($config);
        (new \ReflectionProperty(\EasySwoole\Mysqli\Client::class, 'mysqlClient'))->setValue($connection, $protocol);
        try { $test($connection, $peer); }
        finally { $connection->close(); $peer->close(); }
    }

    private function queueOk(\Swoole\Coroutine\Socket $peer, bool $inTransaction = false): void
    {
        $this->queuePacket($peer, "\0\0\0" . pack('v', $inTransaction ? 1 : 2) . "\0\0");
    }

    private function queuePacket(\Swoole\Coroutine\Socket $peer, string $payload): void
    {
        $packet = substr(pack('V', strlen($payload)), 0, 3) . "\1" . $payload;
        $this->assertSame(strlen($packet), $peer->sendAll($packet, 0.1));
    }

    /**
     * 注入不响应请求的已连接 Socket，跳过握手以单独验证查询和事务超时。
     * mysqli 5.0.3 将传输和会话状态分别交给 PacketStream、SessionState 管理。
     */
    private function createConnectedProtocol(
        \EasySwoole\Mysqli\Config $config,
        \Swoole\Coroutine\Socket $socket,
        bool $inTransaction = false
    ): \EasySwoole\Mysqli\Protocol\Connection {
        $protocol = new \EasySwoole\Mysqli\Protocol\Connection($config);
        $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
        (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
        $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol);
        $state->established();
        // SERVER_STATUS_IN_TRANS 是服务端返回的事务状态位。
        $state->applyServerStatus($inTransaction ? 0x0001 : 0);
        return $protocol;
    }

}
