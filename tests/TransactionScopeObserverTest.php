<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\TransactionScope;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Config as DbConfig;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class TransactionScopeObserverTest extends TestCase
{
    public function testCommitObserverFailureDoesNotRollbackAnAlreadyCommittedTransaction(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('commit logger'); $token = null;
            $db->setOnQuery(static function ($result) use ($failure): void {
                if (str_starts_with($result->getRawSql() ?? '', 'COMMIT')) { throw $failure; }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            try {
                $db->transaction(function () use ($connection, &$token): string { $token = $connection->getTransactionContext()->token(); return 'result'; });
                $this->fail('Expected commit observer error');
            } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($token->wasRolledBack());
            $this->assertFalse($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'COMMIT']);
            $this->assertNoCommand($peer);
        });
    }

    public function testRollbackObserverFailureKeepsTheOriginalBusinessException(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('business failure'); $token = null;
            $db->setOnQuery(static function ($result): void {
                if (str_starts_with($result->getRawSql() ?? '', 'ROLLBACK')) { throw new \RuntimeException('rollback logger'); }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            try {
                $db->transaction(function () use ($connection, &$token, $failure): void { $token = $connection->getTransactionContext()->token(); throw $failure; });
                $this->fail('Expected business error');
            } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($token->wasRolledBack());
            $this->assertFalse($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
            $this->assertNoCommand($peer);
        });
    }

    public function testBeginObserverCannotCommitBeforeTheBusinessCallbackStarts(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $entered = false;
            $db->setOnQuery(static function ($result) use ($connection): void {
                if (str_starts_with($result->getRawSql() ?? '', 'START TRANSACTION')) { $connection->rawQuery('COMMIT'); }
            });
            $this->reply($peer, 1); $this->reply($peer, 2);
            $error = null;
            try { $db->transaction(static function () use (&$entered): void { $entered = true; }); }
            catch (\Throwable $caught) { $error = $caught; }
            $this->assertNotNull($error);
            $this->assertFalse($entered);
            $this->assertFalse($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
            $this->assertNoCommand($peer);
        });
    }

    public function testCommitObserverCanStartANewTransactionThatTheOldOwnerMustNotEnd(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $once = false; $failure = new \RuntimeException('commit observer after new begin');
            $db->setOnQuery(static function ($result) use ($db, $connection, &$once, $failure): void {
                if (!$once && str_starts_with($result->getRawSql() ?? '', 'COMMIT')) {
                    $once = true;
                    $db->begin($connection);
                    throw $failure;
                }
            });
            $this->reply($peer, 1); $this->reply($peer, 2); $this->reply($peer, 1);
            $error = null;
            try { $db->transaction(static fn () => 'done'); } catch (\Throwable $caught) { $error = $caught; }
            $this->assertSame($failure, $error);
            $this->assertTrue($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'COMMIT', 'START TRANSACTION']);
            $this->assertNoCommand($peer);
        });
    }

    public static function unsafeSql(): array
    {
        return [['COMMIT'], ['ROLLBACK'], ['START TRANSACTION'], ['TRUNCATE TABLE scope_rows'],
            ['/* comment */ ALTER TABLE scope_rows ADD COLUMN extra INT'], ['SET /* comment */ @@session.autocommit = 1'],
            ['/*!80000 COMMIT */'], ['LOCK TABLES scope_rows WRITE'], ['SET `autocommit` = 1'],
            ['SET @saved = 1, @@SESSION.`autocommit` := 1'], ['SET TRANSACTION ISOLATION LEVEL READ COMMITTED'],
            ['SET SESSION TRANSACTION READ ONLY'], ["SET PASSWORD FOR 'user' = 'new'"],
            ["SET SESSION transaction_isolation = 'READ-COMMITTED'"], ['/*M!100100 COMMIT */'],
            ["SELECT 'not COMMIT'; /* ignored */ COMMIT"], ["DO 'COMMIT'; /*!80000 COMMIT */"],
            ['SAVEPOINT s'], ['ROLLBACK WORK TO SAVEPOINT s'], ['RELEASE SAVEPOINT s'],
            ['CALL procedure_that_commits()'], ["PREPARE s FROM 'COMMIT'"], ['EXECUTE s'], ['DEALLOCATE PREPARE s'],
            ["XA COMMIT 'id'"], ['FLUSH TABLES'], ['RESET MASTER'], ['DROP TEMPORARY TABLE scope_rows'], ['SET NAMES utf8mb4'],
            ['SET CHARACTER SET utf8mb4'], ['SET CHARSET utf8mb4'], ["SET SESSION sql_mode = 'NO_BACKSLASH_ESCAPES'"],
            ['SET @@session.`character_set_client` = utf8mb4'], ['SET character_set_connection = utf8mb4'],
            ['SET character_set_results = utf8mb4'], ['SET collation_connection = utf8mb4_general_ci'],
            ["SET @x='" . chr(92) . "' , autocommit=1 # ignored quote'"],
            ["/*!80000 SET @x='" . chr(92) . "' , autocommit=1 # ignored quote */"],
            ['SET @x=1 /*!99999 ( */, autocommit=1 /*!99999 ) */'],
            ['SET @x=1 /*M!100000 ( */, autocommit=1 /*M!100000 ) */'],
            ['/*!99999 SELECT */ COMMIT'], ['/*M!100000 SELECT */ COMMIT'],
            ['/*!99999 SELECT */ SET autocommit=1'], ['/*!80000 SELECT 1 */']];
    }

    #[DataProvider('unsafeSql')]
    public function testDirectTransactionBoundariesAndImplicitCommitsAreRejectedBeforeSending(string $sql): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer) use ($sql): void {
            $this->reply($peer, 1); $this->reply($peer, 2);
            $error = null;
            try { $db->transaction(static function () use ($connection, $sql): void { $connection->rawQuery($sql); }); }
            catch (\Throwable $caught) { $error = $caught; }
            $this->assertNotNull($error);
            $this->assertFalse($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
            $this->assertNoCommand($peer);
        });
    }

    public function testPreparedCommitIsRejectedBeforeItCanEvenBePrepared(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->reply($peer, 1); $this->reply($peer, 2);
            $error = null;
            try { $db->transaction(static function () use ($connection): void { $connection->query((new QueryBuilder())->raw('COMMIT')); }); }
            catch (\Throwable $caught) { $error = $caught; }
            $this->assertNotNull($error);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'ROLLBACK']);
            $this->assertNoCommand($peer);
        });
    }

    public function testJoinedAExceptionCaughtInsideBStillMakesTheOwnerRollbackA(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('joined A failed'); $caught = false; $token = null;
            $this->reply($peer, 1); $this->reply($peer, 1); $this->reply($peer, 2);
            $error = null;
            try {
                $db->transaction(function () use ($db, $connection, $failure, &$caught, &$token): void {
                    $token = $connection->getTransactionContext()->token();
                    $db->withConnection('B', function () use ($db, $failure, &$caught): void {
                        try {
                            $db->withConnection('default', function () use ($db, $failure): void {
                                $db->transaction(static function () use ($db, $failure): void { $db->rawQuery('DO 1'); throw $failure; });
                            });
                        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); $caught = true; }
                        $this->assertSame('B', $db->selectConnection());
                    });
                    $this->assertSame('default', $db->selectConnection());
                });
            } catch (\Throwable $caughtError) { $error = $caughtError; }
            $this->assertTrue($caught);
            $this->assertNotNull($error, 'The owner must not silently commit a caught failure from a joined scope');
            $this->assertTrue($token->wasRolledBack());
            $this->assertFalse($connection->isInTransaction);
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'DO 1', 'ROLLBACK']);
            $this->assertNoCommand($peer);
        });
    }

    public function testCommitTimeoutDiscardsTheLostSessionWithoutSendingRollbackOnANewOne(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $context = null;
            $this->reply($peer, 1);
            $error = null;
            try { $db->transaction(function () use ($connection, &$context): void { $context = $connection->getTransactionContext(); }, timeout: 0.01); }
            catch (\Throwable $caught) { $error = $caught; }
            $this->assertInstanceOf(TimeoutException::class, $error);
            $this->assertFalse($protocol->isConnected());
            $this->assertFalse($context->isActive());
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'COMMIT']);
            $packet = $peer->recv(1, 0.002);
            $this->assertFalse(is_string($packet) && $packet !== '', 'Lost-transaction cleanup must not send another command');
        });
    }

    public static function safeSql(): array
    {
        return [["SELECT 'COMMIT; ROLLBACK; SET autocommit=1' /* tail */"],
            ['SELECT `commit`, `autocommit` FROM scope_rows;'], ['SET @autocommit = 1'],
            ['SET @saved = @@session.`autocommit`'], ['SET @saved_mode = @@session.sql_mode'], ['SET @x = ?'], ["SET @password = 'COMMIT; SET autocommit=1'"],
            ['WITH source AS (SELECT 1) SELECT * FROM source'],
            ["/* comment */ SELECT 1 -- comment\n ;"], ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1'],
            ["SELECT '/*!99999 COMMIT */'"],
            ["SELECT 'it" . chr(92) . "'s safe; COMMIT text'"], ["SET @@session.time_zone = '+00:00'"]];
    }

    #[DataProvider('safeSql')]
    public function testNormalSqlQuotedControlTextAndReadOnlySystemVariableReferencesRemainAllowed(string $sql): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default'; $connection->isInTransaction = true;
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->depth = 1;
        $frame = $scope->enterExecution($connection, $sql);
        try { $scope->assertWire($connection, $sql); $this->assertTrue($scope->isCurrent($connection)); }
        finally { $scope->leaveExecution($frame); $connection->finishTransaction(false); }
    }

    public function testOrdinarySqlDoesNotAllocateAnExecutionFrameWhenNoWireTicketIsPending(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default'; $connection->isInTransaction = true;
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->depth = 1;
        try {
            $frame = $scope->enterExecution($connection, 'SELECT 1');
            $this->assertNull($frame);
            $scope->assertWire($connection, 'SELECT 1');
            $scope->leaveExecution($frame);
        } finally { $connection->finishTransaction(false); }
    }

    public function testNestedExecutionRestoresAnUnconsumedOuterWireTicket(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default';
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->depth = 1;
        $scope->authorizeBegin('START TRANSACTION'); $outer = $scope->enterExecution($connection, 'START TRANSACTION');
        try {
            // 自定义连接可能在把外层操作交给实际发送方法前重入查询。
            $connection->isInTransaction = true;
            $inner = $scope->enterExecution($connection, 'SELECT 1');
            $this->assertIsObject($inner);
            $scope->assertWire($connection, 'SELECT 1'); $scope->leaveExecution($inner);
            $connection->isInTransaction = false;
            $scope->assertWire($connection, 'START TRANSACTION');
            $this->assertTrue($scope->isCurrent($connection, false));
        } finally { $scope->leaveExecution($outer); $scope->clearPermit(); $connection->finishTransaction(false); }
    }

    public function testInitialBeginPermitIsNotConsumedByPrecheckAndItsWireTicketIsSingleUse(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default';
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->depth = 1;
        $scope->authorizeBegin('START TRANSACTION');
        $scope->assertControl($connection, 'START TRANSACTION'); $scope->assertControl($connection, 'START TRANSACTION');
        $frame = $scope->enterExecution($connection, 'START TRANSACTION');
        try {
            $scope->assertWire($connection, 'START TRANSACTION');
            try { $scope->assertWire($connection, 'START TRANSACTION'); $this->fail('The BEGIN wire ticket cannot be reused'); }
            catch (RuntimeError $error) { $this->assertStringContainsString('transaction', strtolower($error->getMessage())); }
        } finally { $scope->leaveExecution($frame); $scope->clearPermit(); }
    }

    public function testRejectedCrossCoroutineEntryCannotConsumeTheOwnersPendingBeginTicket(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default';
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->depth = 1;
        $scope->authorizeBegin('START TRANSACTION'); $frame = $scope->enterExecution($connection, 'START TRANSACTION');
        try {
            $channel = new Coroutine\Channel(1);
            Coroutine::create(static function () use ($scope, $connection, $channel): void {
                try { $scope->assertWire($connection, 'START TRANSACTION'); $channel->push(null); }
                catch (\Throwable $error) { $channel->push($error); }
            });
            $this->assertInstanceOf(RuntimeError::class, $channel->pop(0.1));
            $scope->assertWire($connection, 'START TRANSACTION');
            $this->assertTrue($scope->isCurrent($connection, false));
        } finally { $scope->leaveExecution($frame); $scope->clearPermit(); }
    }

    public function testAStaleGenerationCannotSendDmlEvenWhenANewerTransactionIsActive(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default'; $connection->isInTransaction = true;
        $context = $connection->getTransactionContext(); $scope = new TransactionScope($context); $scope->depth = 1;
        $context->finish(false); $connection->isInTransaction = true;
        try {
            $this->assertFalse($scope->isCurrent($connection));
            $scope->assertWire($connection, 'SELECT 1'); $this->fail('A stale scope must not accept the newer transaction');
        } catch (RuntimeError) { $this->addToAssertionCount(1); }
        finally { $connection->finishTransaction(false); }
    }

    public function testRollbackOnlyAfterALeftJoinStillBlocksDirectCommitButAllowsFullRollback(): void
    {
        $connection = new Connection(new Config()); $connection->connectionName = 'default'; $connection->isInTransaction = true;
        $scope = new TransactionScope($connection->getTransactionContext()); $scope->rollbackOnly = true;
        try {
            try { $scope->assertWire($connection, 'COMMIT'); $this->fail('A tainted manual transaction must not commit'); }
            catch (RuntimeError $error) { $this->assertStringContainsString('rollback-only', $error->getMessage()); }
            $scope->assertWire($connection, '/* cleanup */ ROLLBACK WORK AND NO CHAIN NO RELEASE;');
            $this->assertTrue($scope->isCurrent($connection));
        } finally { $connection->finishTransaction(false); }
    }

    public function testDirectConnectionUseFromAnotherCoroutineIsRejectedBeforeAnySql(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->reply($peer, 1); $this->reply($peer, 2);
            $this->assertSame('done', $db->transaction(function () use ($connection): string {
                $channel = new Coroutine\Channel(1);
                Coroutine::create(static function () use ($connection, $channel): void {
                    try { $connection->rawQuery('DO 1'); $channel->push(null); }
                    catch (\Throwable $error) { $channel->push($error); }
                });
                $this->assertInstanceOf(RuntimeError::class, $channel->pop(0.1));
                return 'done';
            }));
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'COMMIT']); $this->assertNoCommand($peer);
        });
    }

    public function testRenamingTheActualConnectionCannotBypassItsManagedGuard(): void
    {
        $this->connected(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->reply($peer, 1); $this->reply($peer, 2);
            $db->transaction(function () use ($connection): void {
                $connection->connectionName = 'renamed';
                try {
                    try { $connection->rawQuery('DO 1'); $this->fail('Renaming a connection must not bypass ownership'); }
                    catch (RuntimeError) { $this->addToAssertionCount(1); }
                } finally { $connection->connectionName = 'default'; }
            });
            $this->assertBoundaryCommands($peer, ['START TRANSACTION', 'COMMIT']); $this->assertNoCommand($peer);
        });
    }

    private function connected(callable $test): void
    {
        $check = function () use ($test): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]); $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol); $state->established(); $state->applyServerStatus(2);
            $connection = new Connection($config); $connection->connectionName = 'default';
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            $db = (new FastDb())->addDb(new DbConfig(), 'default')->addDb(new DbConfig(), 'B');
            $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            try { $test($db, $connection, $peer, $protocol); }
            finally {
                $db->setOnQuery(static function (): void {}); $current->setValue($db, []);
                try { $connection->close(); } catch (\Throwable) {} $peer->close();
            }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); } else { $check(); }
    }
    private function reply(Socket $peer, int $status): void
    {
        $payload = "\0\0\0" . pack('v', $status) . "\0\0";
        $this->assertSame(strlen($payload) + 4, $peer->sendAll(substr(pack('V', strlen($payload)), 0, 3) . "\x01" . $payload, 0.1));
    }
    private function assertBoundaryCommands(Socket $peer, array $prefixes): void
    {
        foreach ($prefixes as $prefix) {
            $header = $peer->recvAll(4, 0.1); $this->assertIsString($header); $this->assertSame(4, strlen($header));
            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $payload = $peer->recvAll($length, 0.1); $this->assertIsString($payload);
            $this->assertSame(3, ord($payload[0]));
            $this->assertStringStartsWith($prefix, substr($payload, 1));
        }
    }
    private function assertNoCommand(Socket $peer): void { $this->assertFalse($peer->recv(1, 0.001), 'Unexpected extra transaction command'); }
}
