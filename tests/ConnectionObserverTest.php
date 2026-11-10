<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class ConnectionObserverTest extends TestCase
{
    public static function queryModes(): array { return [['raw'], ['prepared']]; }

    #[DataProvider('queryModes')]
    public function testNestedGlobalObserverKeepsEachQueryMetadata(string $mode): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode): void {
            $seen = [];
            $db->setOnQuery(function (QueryResult $result) use ($db, $mode, &$seen): void {
                $this->assertTrue($result->getResult());
                $this->assertLessThanOrEqual(microtime(true), $result->getStartTime());
                $seen[] = [$result->getAffectedRows(), $result->getInsertId()];
                if (count($seen) === 1) { $this->execute($db, $mode, 'DO 2'); }
            });
            $this->queueQuery($peer, $mode, $this->ok(2, 1, 11));
            $this->queueQuery($peer, $mode, $this->ok(2, 2, 22));
            $result = $this->execute($db, $mode, 'DO 1');
            $this->assertSame([[1, 11], [2, 22]], $seen);
            $this->assertSame(1, $result->getAffectedRows());
            $this->assertSame(11, $result->getInsertId());
        });
    }

    #[DataProvider('queryModes')]
    public function testRollbackStateAndBaselinesAreSynchronizedBeforeAGlobalObserverThrows(string $mode): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode): void {
            $entity = $this->remember($connection);
            $failure = new \RuntimeException('observer failed');
            $db->setOnQuery(function (QueryResult $result) use ($connection, $entity, $failure): void {
                $this->assertTrue($result->getResult());
                $this->assertFalse($connection->isInTransaction);
                $this->assertSame(0, $entity->value);
                throw $failure;
            });
            $this->queueQuery($peer, $mode, $this->ok());
            try { $this->execute($db, $mode, 'ROLLBACK'); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction);
            $this->assertSame(0, $entity->value);
        }, true);
    }

    public function testCallableQuerySnapshotPrecedesAGlobalObserverRebuildingTheSameBuilder(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $builder = null; $once = false;
            $db->setOnQuery(static function () use ($db, &$builder, &$once): void {
                if (!$once) { $once = true; $db->query($builder->raw('DO 2')); }
            });
            $this->queueQuery($peer, 'prepared', $this->ok(2, 1, 11));
            $this->queueQuery($peer, 'prepared', $this->ok(2, 2, 22));
            $result = $db->query(static function (QueryBuilder $query) use (&$builder): void { $builder = $query; $query->raw('DO 1'); });
            $this->assertSame('DO 1', $result->getQueryBuilder()->getLastPrepareQuery());
            $this->assertSame('DO 2', $builder->getLastPrepareQuery());
            $this->assertSame(1, $result->getAffectedRows());
        });
    }

    public function testFailedExecutionCompletesOnceBeforeAnErrorObserverReusesTheBuilder(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $builder = (new QueryBuilder())->raw('DO 1'); $completed = []; $retried = false;
            $db->setOnQuery(static function (QueryResult $result) use ($db, $builder, &$retried): void {
                if ($result->getException() !== null && !$retried) { $retried = true; $db->query($builder->raw('DO 2')); }
            });
            $this->queueQuery($peer, 'prepared', "\xff" . pack('v', 1064) . '#42000syntax error');
            $this->queueQuery($peer, 'prepared', $this->ok());
            try {
                $db->query($builder, onComplete: static function (QueryResult $result) use (&$completed): void { $completed[] = $result; });
                $this->fail('Expected SQL error');
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); }
            $this->assertTrue($retried);
            $this->assertCount(1, $completed);
            $this->assertSame($error, $completed[0]->getException());
            $this->assertSame('DO 1', $completed[0]->getQueryBuilder()->getLastPrepareQuery());
            $this->assertNull((new \ReflectionProperty(Connection::class, 'executionFrame'))->getValue($connection));
        });
    }

    public function testGlobalObserverReplacementPersistsAfterTheCurrentQuery(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $seen = [];
            $db->setOnQuery(static function () use ($db, &$seen): void {
                $seen[] = 'first';
                $db->setOnQuery(static function () use (&$seen): void { $seen[] = 'replacement'; });
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok());
            $db->rawQuery('DO 1'); $db->rawQuery('DO 2');
            $this->assertSame(['first', 'replacement'], $seen);
        });
    }

    public function testFactoryCanExecuteTheSameBuilderBeforeCompilingItsOwnQuery(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $completed = [];
            $this->queueQuery($peer, 'prepared', $this->ok(2, 1, 11));
            $this->queueQuery($peer, 'prepared', $this->ok(2, 2, 22));
            $result = $db->query(static function (QueryBuilder $builder) use ($connection): void {
                $connection->query($builder->raw('DO inner_query'));
                $builder->raw('DO outer_query');
            }, onComplete: static function (QueryResult $result) use (&$completed): void { $completed[] = $result; });
            $this->assertSame('DO outer_query', $result->getQueryBuilder()->getLastPrepareQuery());
            $this->assertSame(2, $result->getAffectedRows());
            $this->assertSame(22, $result->getInsertId());
            $this->assertSame([$result], $completed);
            $commands = [];
            for ($i = 0; $i < 6; $i++) {
                $header = $peer->recvAll(4, 0.1);
                $this->assertIsString($header);
                $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
                $commands[] = $peer->recvAll($length, 0.1);
            }
            $this->assertSame("\x16DO inner_query", $commands[0]);
            $this->assertSame("\x16DO outer_query", $commands[3]);
        });
    }

    public function testCompletionErrorsDoNotReplaceSqlErrorsOrRepeatCompletion(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = []; $completed = null; $logged = null;
            $db->setOnQuery(static function (QueryResult $result) use (&$events, &$logged): void { $events[] = 'global'; $logged = $result; });
            $this->queueQuery($peer, 'raw', "\xff" . pack('v', 1064) . '#42000syntax error');
            try {
                $db->rawQuery('invalid sql', onComplete: static function (QueryResult $result) use (&$events, &$completed): void {
                    $events[] = 'completion'; $completed = $result;
                    throw new \RuntimeException('completion failed');
                });
                $this->fail('Expected SQL error');
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); }
            $this->assertSame(['completion', 'global'], $events);
            $this->assertSame($completed, $logged);
            $this->assertSame($error, $completed->getException());
            $this->assertNull($completed->getResult());
            $this->assertNull((new \ReflectionProperty(Connection::class, 'executionFrame'))->getValue($connection));
        });
    }

    public function testCompletionErrorKeepsPriorityOverAGlobalObserverErrorAfterSuccessfulSql(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $events = []; $logged = null; $failure = new \RuntimeException('completion failed');
            $db->setOnQuery(static function (QueryResult $result) use (&$events, &$logged): void {
                $events[] = 'global'; $logged = $result;
                throw new \RuntimeException('global observer failed');
            });
            $this->queueQuery($peer, 'raw', $this->ok(2, 1, 11));
            try {
                $db->rawQuery('DO 1', onComplete: static function () use (&$events, $failure): void { $events[] = 'completion'; throw $failure; });
                $this->fail('Expected completion error');
            } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertSame(['completion', 'global'], $events);
            $this->assertTrue($logged->getResult());
            $this->assertNull($logged->getException());
            $this->assertSame(1, $logged->getAffectedRows());
            $this->assertSame(11, $logged->getInsertId());
        });
    }

    public function testAStoredResultDoesNotRetainItsCompletionClosure(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $marker = new \stdClass(); $weak = \WeakReference::create($marker);
            $this->queueQuery($peer, 'raw', $this->ok());
            $result = $db->rawQuery('DO 1', onComplete: static function () use ($marker): void {});
            unset($marker);
            $this->assertNull($weak->get());
            $this->assertTrue($result->getResult());
        });
    }

    public function testSharedDispatcherDoesNotKeepAnUnusedConnectionAlive(): void
    {
        $connection = new Connection(new Config());
        $weak = \WeakReference::create($connection);
        unset($connection);
        $this->assertNull($weak->get());
    }

    public function testCommitObserverCanBeginANewTransactionWithoutItBeingClearedOnReturn(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->remember($connection); $once = false;
            $db->setOnQuery(function () use ($db, $connection, &$once): void {
                if (!$once) { $once = true; $this->assertFalse($connection->isInTransaction); $this->assertTrue($db->begin()); }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertTrue($db->commit());
            $this->assertTrue($connection->isInTransaction);
            $this->assertTrue($connection->getTransactionContext()->isActive());
        }, true);
    }

    public function testRawCommitWithoutAnActiveTransactionPreservesOneStartedByItsGlobalObserver(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $once = false;
            $db->setOnQuery(static function () use ($db, &$once): void {
                if (!$once) { $once = true; $db->begin(); }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertTrue($db->rawQuery('COMMIT AND NO CHAIN NO RELEASE')->getResult());
            $this->assertTrue($connection->isInTransaction);
        });
    }

    public function testGlobalObserverQueryErrorDoesNotRollbackANewerTransactionTwice(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $once = false; $failure = new \EasySwoole\Mysqli\Exception\Exception('observer error', 1213);
            $context = $connection->getTransactionContext(); $version = $context->version();
            $db->setOnQuery(static function () use ($db, &$once, $failure): void {
                if (!$once) { $once = true; $db->begin(); throw $failure; }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            try { $db->commit(); $this->fail('Expected observer error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($connection->isInTransaction);
            $this->assertTrue($context->isActive());
            $this->assertSame($version + 1, $context->version());
        }, true);
    }

    public function testBeginObserverCanRollbackWithoutTheFacadeRestoringTheActiveFlag(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $once = false;
            $db->setOnQuery(function () use ($db, &$once): void {
                if (!$once) { $once = true; $this->assertTrue($db->rollback()); }
            });
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue($db->begin());
            $this->assertFalse($connection->isInTransaction);
        });
    }

    public function testConnectionObserverRegistrationRejectsPayloadWithoutReplacingTheDispatcher(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $entity = $this->remember($connection); $called = false;
            $request = (object) ['payload' => str_repeat('r', 1024 * 1024)];
            $weak = \WeakReference::create($request);
            try {
                $connection->onQuery(static function () use ($request, &$called): void { $called = true; });
                $this->fail('Connection-level registration must be rejected');
            } catch (\LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            // 捕获的异常也可能通过调用栈保留回调参数。
            unset($error, $request);
            gc_collect_cycles();
            $this->assertNull($weak->get());
            $this->assertTrue((new \ReflectionMethod(Connection::class, 'onQuery'))->isFinal());
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue($connection->rawQuery('ROLLBACK'));
            $this->assertFalse($called);
            $this->assertFalse($connection->isInTransaction);
            $this->assertSame(0, $entity->value);
        }, true);
    }

    public function testDirectRawCommitSynchronizesBaselinesWithoutCallingTheFacadeObserver(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $entity = $this->remember($connection); $token = $connection->getTransactionContext()->token(); $calls = 0;
            $db->setOnQuery(static function () use (&$calls): void { $calls++; });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue($connection->rawQuery('COMMIT'));
            $this->assertFalse($connection->isInTransaction);
            $this->assertFalse($token->wasRolledBack());
            $this->assertSame(7, $entity->value);
            $this->assertSame(0, $calls);
        }, true);
    }

    public function testClosingAnUnfinishedTransactionRestoresAndInvalidatesItsContext(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection): void {
            $entity = $this->remember($connection);
            $context = $connection->getTransactionContext(); $token = $context->token();
            $this->assertTrue($connection->close());
            $this->assertFalse($connection->isInTransaction);
            $this->assertFalse($context->isActive());
            $this->assertTrue($token->wasRolledBack());
            $this->assertSame(0, $entity->value);
            $this->assertNotSame($context, $connection->getTransactionContext());
        }, true);
    }

    public function testRejectedCloseKeepsTheActiveTransactionAndItsBaselines(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $entity = $this->remember($connection); $context = $connection->getTransactionContext();
            $tickProperty = new \ReflectionProperty(Connection::class, 'lastActiveTick');
            $tick = hrtime(true); $tickProperty->setValue($connection, $tick);
            $busy = new \ReflectionProperty($protocol, 'busy'); $busy->setValue($protocol, true);
            try {
                try { $connection->close(); $this->fail('Expected concurrent close rejection'); }
                catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertStringContainsString('Concurrent', $error->getMessage()); }
                $this->assertTrue($context->isActive());
                $this->assertSame($context, $connection->getTransactionContext());
                $this->assertSame(7, $entity->value);
                $this->assertTrue($protocol->isConnected());
                $this->assertSame($tick, $tickProperty->getValue($connection));
            } finally { $busy->setValue($protocol, false); }
        }, true);
    }

    public function testReleaseCommitsAndClosesBeforeAGlobalObserverThrows(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $entity = $this->remember($connection); $token = $connection->getTransactionContext()->token();
            $failure = new \RuntimeException('release observer');
            $db->setOnQuery(function () use ($connection, $failure): void {
                $this->assertFalse($connection->isInTransaction);
                $this->assertNull($connection->mysqlClient());
                throw $failure;
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            try { $db->commit(flags: TransactionCompletionFlags::Release); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($token->wasRolledBack());
            $this->assertSame(7, $entity->value);
            $this->assertFalse($connection->isInTransaction);
            $this->assertNull($connection->mysqlClient());
        }, true);
    }

    public function testDisconnectedRestoreEndsLocalContextWithoutSendingRollback(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $entity = $this->remember($connection); $context = $connection->getTransactionContext();
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol); $stream->close();
            $connection->objectRestore();
            $this->assertSame(0, $entity->value);
            $this->assertFalse($context->isActive());
            $this->assertFalse($connection->isInTransaction);
            $this->assertNull($connection->mysqlClient());
            $data = $peer->recv(1, 0.01);
            $this->assertFalse(is_string($data) && $data !== '', 'Disconnected cleanup must not send ROLLBACK');
        }, true);
    }

    private function remember(Connection $connection): object
    {
        $entity = (object) ['value' => 7];
        $connection->getTransactionContext()->rememberBaseline($entity, ['value' => 0],
            static function (object $entity, array $baseline): void { $entity->value = $baseline['value']; });
        return $entity;
    }

    private function execute(FastDb $db, string $mode, string $sql): QueryResult
    {
        return $mode === 'raw' ? $db->rawQuery($sql) : $db->query((new QueryBuilder())->raw($sql));
    }

    private function withConnection(callable $test, bool $transaction = false): void
    {
        $check = function () use ($test, $transaction): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.03]); $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol); $state->established(); $state->applyServerStatus($transaction ? 1 : 2);
            $connection = new Connection($config); $connection->connectionName = 'default'; $connection->isInTransaction = $transaction;
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($connection, $protocol);
            $db = new FastDb(); $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            try { $test($db, $connection, $peer, $protocol); }
            finally { $db->setOnQuery(static function (): void {}); $current->setValue($db, []); $connection->close(); $peer->close(); }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); } else { $check(); }
    }

    private function queueQuery(Socket $peer, string $mode, string $result): void
    {
        if ($mode === 'prepared') { $this->reply($peer, "\0" . pack('VvvCv', 7, 0, 0, 0, 0)); }
        $this->reply($peer, $result);
    }

    private function ok(int $status = 2, int $affected = 0, int $insertId = 0): string { return "\0" . chr($affected) . chr($insertId) . pack('v', $status) . "\0\0"; }
    private function reply(Socket $peer, string $payload): void { $this->assertSame(strlen($payload) + 4, $peer->sendAll(substr(pack('V', strlen($payload)), 0, 3) . "\x01" . $payload, 0.1)); }
}
