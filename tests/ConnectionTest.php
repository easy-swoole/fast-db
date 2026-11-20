<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config as FastDbConfig;
use EasySwoole\FastDb\Exception\Exception as FastDbException;
use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Mysql\TransactionRecycleAction;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\Exception;
use EasySwoole\Mysqli\Exception\TimeoutException as DriverTimeoutException;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use Error;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use TypeError;

final class ConnectionTest extends TestCase
{
    public function testRestoreAllowsStartingANewTransaction(): void
    {
        $this->withConnected(function (Connection $connection, Socket $peer): void {
            $this->queueOk($peer);
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
            $this->queueOk($peer, true);
            $this->assertTrue((new FastDb())->begin($connection));
            $this->assertTrue($connection->isInTransaction());
        }, true);
    }

    public static function recycleActions(): array
    {
        return array_map(static fn (TransactionRecycleAction $action) => [$action], TransactionRecycleAction::cases());
    }

    public static function recycleStates(): array
    {
        $cases = [];
        foreach (TransactionRecycleAction::cases() as $action) {
            $cases[$action->value . '-active'] = [$action, true];
            $cases[$action->value . '-idle'] = [$action, false];
        }
        return $cases;
    }

    #[DataProvider('recycleStates')]
    public function testRestoreAppliesTheConfiguredActionOnlyToAnActiveTransaction(
        TransactionRecycleAction $action, bool $active
    ): void {
        $this->withConnected(function (Connection $connection, Socket $peer) use ($action, $active): void {
            $connection->transactionRecycleAction = $action;
            $sendsCommand = $active && $action !== TransactionRecycleAction::None;
            if ($sendsCommand) { $this->queueOk($peer); }

            $connection->objectRestore();

            $this->assertSame($active && $action === TransactionRecycleAction::None, $connection->isInTransaction());
            $this->assertSame($action, $connection->transactionRecycleAction);
            $this->assertNotNull($connection->mysqlClient());
            if ($sendsCommand) {
                $sql = strtoupper($action->value) . ' AND NO CHAIN NO RELEASE';
                $command = $peer->recvAll(5 + strlen($sql), 0.1);
                $this->assertSame("\x03" . $sql, substr($command, 4));
            } else {
                $this->assertFalse($peer->recv(1, 0.001));
            }
        }, $active);
    }

    #[DataProvider('recycleActions')]
    public function testRecycleActionPersistsAcrossRestores(TransactionRecycleAction $action): void
    {
        $this->withConnected(function (Connection $connection, Socket $peer) use ($action): void {
            $connection->transactionRecycleAction = $action;
            if ($action !== TransactionRecycleAction::None) { $this->queueOk($peer); }
            $connection->objectRestore();
            $connection->objectRestore();
            $this->assertSame($action, $connection->transactionRecycleAction);
            $this->assertSame($action === TransactionRecycleAction::None, $connection->isInTransaction());
        }, true);
    }

    public static function cleanupModes(): array
    {
        $cases = [];
        foreach (TransactionRecycleAction::cases() as $action) {
            foreach (['objectRestore', 'gc'] as $method) {
                $cases[] = [$action, $method];
            }
        }
        return $cases;
    }

    #[DataProvider('cleanupModes')]
    public function testRecycleActionDoesNotOpenAClosedSession(TransactionRecycleAction $action, string $method): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->setConstructorArgs([new Config()])
            ->onlyMethods(['rawQuery'])->getMock();
        $connection->transactionRecycleAction = $action;
        $connection->close();
        $connection->expects($this->never())->method('rawQuery');
        $connection->$method();
        $this->assertNull($connection->mysqlClient());
    }

    public static function transactionCompletionActions(): array
    {
        return [[TransactionRecycleAction::Commit], [TransactionRecycleAction::Rollback]];
    }

    #[DataProvider('transactionCompletionActions')]
    public function testFailedRestorePreservesTheOriginalSqlError(TransactionRecycleAction $action): void
    {
        $this->withConnected(function (Connection $connection, Socket $peer) use ($action): void {
            $connection->transactionRecycleAction = $action;
            $payload = "\xff" . pack('v', 1064) . '#42000completion failed';
            $this->queuePacket($peer, $payload);
            $this->queueOk($peer);
            try { $connection->objectRestore(); $this->fail('Expected transaction SQL error'); }
            catch (Exception $error) {
                $this->assertSame(1064, $error->getCode());
                $this->assertStringContainsString('completion failed', $error->getMessage());
            }
            $this->assertTrue($connection->isInTransaction());
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    #[DataProvider('recycleActions')]
    public function testDiscardedConnectionClosesWithoutCompletingTheTransaction(TransactionRecycleAction $action): void
    {
        $this->withConnected(function (Connection $connection, Socket $peer) use ($action): void {
            $connection->transactionRecycleAction = $action;
            $connection->gc();
            $this->assertFalse($connection->isInTransaction());
            $this->assertNull($connection->mysqlClient());
            $this->assertSame("\x01\0\0\0\x01", $peer->recvAll(5, 0.1));
        }, true);
    }

    public function testTransactionRecycleActionConfiguration(): void
    {
        $config = new FastDbConfig();
        $this->assertSame(TransactionRecycleAction::Rollback, $config->getTransactionRecycleAction());
        foreach (TransactionRecycleAction::cases() as $action) {
            $config = new FastDbConfig(['transactionRecycleAction' => $action]);
            $this->assertSame($action, $config->getTransactionRecycleAction());
            $this->assertSame($action, $config->toArray()['transactionRecycleAction']);
        }
        $config->setTransactionRecycleAction(TransactionRecycleAction::Commit);
        $this->assertSame(TransactionRecycleAction::Commit, $config->getTransactionRecycleAction());
        $config->restore(['transactionRecycleAction' => TransactionRecycleAction::None]);
        $this->assertSame(TransactionRecycleAction::None, $config->getTransactionRecycleAction());
        $this->assertArrayNotHasKey('transactionRecycleAction', $config->toMysqliConfig()->toArray());
    }

    public function testQueryTimeoutReachesMysqliClient(): void
    {
        $connection = new Connection(new Config());
        $builder = new QueryBuilder();
        $builder->raw('SELECT 1');
        $this->expectException(InvalidArgumentException::class);
        $connection->query($builder, 0.0);
    }

    public function testRawQueryTimeoutReachesMysqliClient(): void
    {
        $connection = new Connection(new Config());
        $this->expectException(InvalidArgumentException::class);
        $connection->rawQuery('SELECT 1', 0.0);
    }

    public function testEnumTransactionFlagsPreserveChainState(): void
    {
        $this->withConnected(function (Connection $connection, Socket $peer): void {
            $this->queueOk($peer, true); $this->queueOk($peer, true);
            $db = new FastDb();
            $this->assertTrue($db->begin($connection, TransactionStartFlags::ReadOnly));
            $this->assertTrue($db->commit($connection, TransactionCompletionFlags::Chain));
            $this->assertTrue($connection->isInTransaction());
            $this->assertTrue($connection->mysqlClient()->inTransaction());
        });
    }

    public function testMysqliConfigReceivesTimeoutSettings(): void
    {
        $config = new FastDbConfig(['timeout' => 7, 'maxConnectTime' => 8, 'compress' => true]);
        $driver = $config->toMysqliConfig();
        $this->assertSame(7.0, $driver->getTimeout());
        $this->assertSame(8.0, $driver->getMaxConnectTime());
        $this->assertTrue($driver->isCompress());
        $config->setMaxConnectTime(9);
        $this->assertSame(9.0, $config->toMysqliConfig()->getMaxConnectTime());
    }

    public function testTestDbPreservesConnectionFailure(): void
    {
        $db = (new FastDb())->addDb(new FastDbConfig(['maxConnectTime' => -1.0]));
        $this->expectException(InvalidArgumentException::class);
        $db->testDb();
    }

    public function testTransactionFlagsAndTimeoutReachSpecifiedConnection(): void
    {
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $flags = $operation === 'begin'
                ? TransactionStartFlags::ReadOnly
                : TransactionCompletionFlags::NoChainNoRelease;
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
                $config = new Config(['timeout' => 2.0]);
                $protocol = $this->createConnectedProtocol($config, $socket, $operation !== 'begin');
                $connection = new Connection($config);
                (new ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
                $flags = $operation === 'begin'
                    ? TransactionStartFlags::ReadOnly
                    : TransactionCompletionFlags::NoChainNoRelease;
                $started = microtime(true);
                try {
                    (new FastDb())->$operation($connection, $flags, 0.03);
                    $this->fail('Expected transaction timeout');
                } catch (TimeoutException $error) {
                    $elapsed = microtime(true) - $started;
                    $this->assertGreaterThanOrEqual(0.015, $elapsed);
                    $this->assertLessThan(0.5, $elapsed);
                    $this->assertFalse($protocol->isConnected());
                    $this->assertFalse($connection->isInTransaction());
                    $this->assertInstanceOf(DriverTimeoutException::class, $error->getPrevious());
                    $this->assertSame($error->getPrevious()->getCode(), $error->getCode());
                    $expected = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
                    $this->assertSame($expected, $error->getRawSql());
                    $this->assertSame([], $error->getLastBindParams());
                    $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
                    $packet = $peer->recvAll(5 + strlen($expected), 1.0);
                    $this->assertSame("\x03" . $expected, substr($packet, 4));
                } finally {
                    $connection->close();
                    $peer->close();
                }
            }
        };
        if (Coroutine::getCid() < 0) {
            Coroutine\run($check);
        } else {
            $check();
        }
    }

    public function testReleaseFlagClosesConnectionOnlyAfterSuccessfulCommit(): void
    {
        $flags = TransactionCompletionFlags::Release;
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['rawQuery', 'close', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method('rawQuery')->with('COMMIT RELEASE', 0.05)->willReturn(true);
        $connection->expects($this->once())->method('close')->willReturn(true);
        $this->assertTrue($connection->commitTransaction($flags, 0.05));
    }

    public function testTransactionFlagsRejectIntegersAndWrongEnumTypes(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['rawQuery', 'isInTransaction'])->getMock();
        $connection->expects($this->never())->method('rawQuery');
        $connection->method('isInTransaction')->willReturn(true);
        $db = new FastDb();
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $wrongEnum = $operation === 'begin'
                ? TransactionCompletionFlags::None
                : TransactionStartFlags::None;
            foreach ([0, 1, $wrongEnum] as $flags) {
                try {
                    $db->$operation($connection, $flags, 0.05);
                    $this->fail('Expected invalid transaction flags to be rejected');
                } catch (TypeError $error) {
                    $this->assertStringContainsString('flags', $error->getMessage());
                }
            }
        }
    }

    public function testTransactionLogsMatchExecutedSqlForEveryFlag(): void
    {
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $cases = $operation === 'begin'
                ? TransactionStartFlags::cases()
                : TransactionCompletionFlags::cases();
            foreach ($cases as $flags) {
                $expected = $operation === 'begin' ? $flags->toSql() : strtoupper($operation) . $flags->toSqlSuffix();
                $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
                    ->onlyMethods(['rawQuery', 'close', 'isInTransaction'])->getMock();
                $connection->method('isInTransaction')->willReturn($operation !== 'begin');
                $connection->expects($this->once())->method('rawQuery')->with($expected, 0.05)->willReturn(true);
                $releases = $operation !== 'begin' && $flags->releasesConnection();
                $connection->expects($releases ? $this->once() : $this->never())->method('close')->willReturn(true);
                $db = (new FastDb())->configureQueryStack(true);
                $observed = null;
                $db->setOnQuery(static function (QueryResult $result) use (&$observed): void {
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
        $connection = new Connection(new Config());
        $this->expectException(InvalidArgumentException::class);
        $connection->query(new QueryBuilder(), 0.0);
    }

    public function testConfiguredTimeoutIsReportedWithoutExplicitOverride(): void
    {
        $check = function (): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]);
            $protocol = $this->createConnectedProtocol($config, $socket);
            $connection = new Connection($config);
            $connection->connectionName = 'reporting';
            (new ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            try {
                $connection->rawQuery('SELECT 1');
                $this->fail('Expected timeout');
            } catch (TimeoutException $error) {
                $this->assertSame('SELECT 1', $error->getRawSql());
                $this->assertSame([], $error->getLastBindParams());
                $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
                $this->assertSame($error->getPrevious()->getCode(), $error->getCode());
            } finally { $connection->close(); $peer->close(); }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); }
        else { $check(); }
    }

    public function testTimeoutIsCaughtByFastDbBaseException(): void
    {
        $cause = new DriverTimeoutException('query timed out', 110);
        $error = new TimeoutException($cause->getMessage(), 110, $cause, 'SELECT 1');
        try {
            throw $error;
        } catch (FastDbException $caught) {
            $this->assertSame($error, $caught);
            $this->assertSame($cause, $caught->getPrevious());
            $this->assertSame('SELECT 1', $caught->getRawSql());
            $this->assertSame(110, $caught->getCode());
        }
    }

    public function testTimeoutSqlContextIsReadOnlyAndSurvivesBuilderReuse(): void
    {
        $empty = new TimeoutException('query timed out');
        $this->assertNull($empty->getRawSql());
        $this->assertNull($empty->getLastPrepareQuery());
        $this->assertSame([], $empty->getLastBindParams());

        $builder = new class extends QueryBuilder {
            private function __clone() {}
        };
        $builder->noBackslashEscapes()->raw('SELECT ?', ["a'b"]);
        $sql = $builder->getLastQuery();
        $error = new TimeoutException('query timed out', rawSql: 'SELECT ?', builder: $builder);
        $builder->raw('SELECT 2');
        $params = $error->getLastBindParams();
        $params[0] = 'changed';
        $this->assertSame('SELECT ?', $error->getRawSql());
        $this->assertSame('SELECT ?', $error->getLastPrepareQuery());
        $this->assertSame($sql, $error->getLastQuery());
        $this->assertSame(["a'b"], $error->getLastBindParams());
        $this->assertFalse(method_exists($error, 'getQueryBuilder'));

        $this->expectException(Error::class);
        $error->rawSql = 'SELECT 3';
    }

    public static function failedTransactionModes(): array
    {
        return [['begin', false], ['begin', true], ['commit', false], ['commit', true], ['rollback', false], ['rollback', true]];
    }

    #[DataProvider('failedTransactionModes')]
    public function testFailedTransactionRecordsTheSqlErrorAndPropagatesObserverExceptions(string $operation, bool $loggerThrows): void
    {
        $failure = new RuntimeException('transaction execution failed');
        $observerFailure = new RuntimeException('logger failed');
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['rawQuery', 'isInTransaction'])->getMock();
        $connection->expects($this->once())->method('rawQuery')->willThrowException($failure);
        $connection->method('isInTransaction')->willReturn($operation !== 'begin');
        $db = (new FastDb())->configureQueryStack(true);
        $called = 0;
        $expected = $operation === 'begin' ? 'START TRANSACTION' : strtoupper($operation) . ' AND NO CHAIN NO RELEASE';
        $db->setOnQuery(function (QueryResult $result) use ($failure, $observerFailure, $expected, $loggerThrows, &$called): void {
            $called++;
            $this->assertSame($failure, $result->getException());
            $this->assertSame($expected, $result->getRawSql());
            $this->assertNull($result->getAffectedRows());
            $this->assertNull($result->getResult());
            if ($loggerThrows) { throw $observerFailure; }
        });
        try {
            $db->$operation($connection);
            $this->fail('Expected transaction error');
        } catch (RuntimeException $error) {
            $this->assertSame($loggerThrows ? $observerFailure : $failure, $error);
        }
        $this->assertSame(1, $called);
        $this->assertSame($expected, $db->getQueryStack(-1)->rawQuery);
        $this->assertSame($failure, $db->getQueryStack(-1)->exception);
        $this->assertSame($operation !== 'begin', $connection->isInTransaction());
    }


    /** 使用已连接的真实协议 Socket，测试不依赖 MySQL 服务。 */
    private function withConnected(callable $test, bool $inTransaction = false): void
    {
        [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
        $config = new Config(['timeout' => 0.03]);
        $protocol = $this->createConnectedProtocol($config, $socket, $inTransaction);
        $connection = new Connection($config);
        (new ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
        try { $test($connection, $peer); }
        finally { $connection->close(); $peer->close(); }
    }

    private function queueOk(Socket $peer, bool $inTransaction = false): void
    {
        $this->queuePacket($peer, "\0\0\0" . pack('v', $inTransaction ? 1 : 2) . "\0\0");
    }

    private function queuePacket(Socket $peer, string $payload): void
    {
        $packet = substr(pack('V', strlen($payload)), 0, 3) . "\1" . $payload;
        $this->assertSame(strlen($packet), $peer->sendAll($packet, 0.1));
    }

    /**
     * 注入不响应请求的已连接 Socket，跳过握手以单独验证查询和事务超时。
     * mysqli 5.0.3 将传输和会话状态分别交给 PacketStream、SessionState 管理。
     */
    private function createConnectedProtocol(
        Config $config,
        Socket $socket,
        bool $inTransaction = false
    ): ProtocolConnection {
        $protocol = new ProtocolConnection($config);
        $stream = (new ReflectionProperty($protocol, 'stream'))->getValue($protocol);
        (new ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
        $state = (new ReflectionProperty($protocol, 'state'))->getValue($protocol);
        $state->established();
        // SERVER_STATUS_IN_TRANS 是服务端返回的事务状态位。
        $state->applyServerStatus($inTransaction ? 0x0001 : 0);
        return $protocol;
    }

}
