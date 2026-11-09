<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\QueryExecutor;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

final class ConnectionObserverTest extends TestCase
{
    public static function queryModes(): array { return [['raw'], ['prepared']]; }

    #[DataProvider('queryModes')]
    public function testNestedObserverKeepsEachQueryMetadata(string $mode): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer) use ($mode): void {
            $db = new FastDb();
            $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            $seen = [];
            $connection->onQuery(function ($result, $client, float $startedAt) use ($connection, $mode, &$seen): void {
                $this->assertSame($connection, $client);
                $this->assertTrue($result);
                $this->assertLessThanOrEqual(microtime(true), $startedAt);
                $seen[] = [$client->getLastAffectRows(), $client->getLastInsertId()];
                if (count($seen) === 1) { $this->execute($connection, $mode, 'DO 2'); }
            });
            $this->queueQuery($peer, $mode, $this->ok(2, 1, 11));
            $this->queueQuery($peer, $mode, $this->ok(2, 2, 22));
            try {
                $result = $mode === 'raw' ? $db->rawQuery('DO 1') : $db->query((new QueryBuilder())->raw('DO 1'));
                $this->assertSame([[1, 11], [2, 22]], $seen);
                $this->assertSame(1, $result->getAffectedRows());
                $this->assertSame(11, $result->getInsertId());
            } finally { $current->setValue($db, []); }
        });
    }

    #[DataProvider('queryModes')]
    public function testRollbackStateAndBaselinesAreSynchronizedBeforeAnObserverThrows(string $mode): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer) use ($mode): void {
            $entity = $this->remember($connection);
            $failure = new \RuntimeException('observer failed');
            $connection->onQuery(function () use ($connection, $entity, $failure): void {
                $this->assertFalse($connection->isInTransaction);
                $this->assertSame(0, $entity->value);
                throw $failure;
            });
            $this->queueQuery($peer, $mode, $this->ok());
            try { $this->execute($connection, $mode, 'ROLLBACK'); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction);
            $this->assertSame(0, $entity->value);
        }, true);
    }

    public function testCallableQuerySnapshotPrecedesAnObserverRebuildingTheSameBuilder(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $db = new FastDb(); $builder = null; $once = false;
            $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            $connection->onQuery(function () use ($connection, &$builder, &$once): void {
                if (!$once) { $once = true; $connection->query($builder->raw('DO 2')); }
            });
            $this->queueQuery($peer, 'prepared', $this->ok(2, 1, 11));
            $this->queueQuery($peer, 'prepared', $this->ok(2, 2, 22));
            try {
                $result = $db->query(static function (QueryBuilder $query) use (&$builder): void { $builder = $query; $query->raw('DO 1'); });
                $this->assertSame('DO 1', $result->getQueryBuilder()->getLastPrepareQuery());
                $this->assertSame('DO 2', $builder->getLastPrepareQuery());
                $this->assertSame(1, $result->getAffectedRows());
            } finally { $current->setValue($db, []); }
        });
    }

    public function testFailedExecutionDiscardsItsCompletionBeforeAnErrorObserverReusesTheBuilder(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $db = new FastDb(); $builder = (new QueryBuilder())->raw('DO 1'); $completed = 0; $retried = false;
            $cancel = QueryExecutor::onComplete($builder, static function () use (&$completed): void { $completed++; });
            $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
            $current->setValue($db, [Coroutine::getCid() => ['default' => $connection]]);
            $db->setOnQuery(function ($result) use ($db, $builder, &$retried): void {
                if ($result->getException() !== null && !$retried) { $retried = true; $db->query($builder->raw('DO 2')); }
            });
            $this->queueQuery($peer, 'prepared', "\xff" . pack('v', 1064) . '#42000syntax error');
            $this->queueQuery($peer, 'prepared', $this->ok());
            try {
                try { $db->query($builder); $this->fail('Expected SQL error'); }
                catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); }
                $this->assertTrue($retried);
                $this->assertSame(0, $completed);
                $listeners = (new \ReflectionProperty(QueryExecutor::class, 'completionObservers'))->getValue();
                $this->assertCount(0, $listeners);
            } finally { $cancel(); $current->setValue($db, []); }
        });
    }

    public function testObserverReplacementPersistsAfterTheCurrentQuery(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $seen = [];
            $connection->onQuery(function () use ($connection, &$seen): void {
                $seen[] = 'first';
                $connection->onQuery(static function () use (&$seen): void { $seen[] = 'replacement'; });
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok());
            $connection->rawQuery('DO 1'); $connection->rawQuery('DO 2');
            $this->assertSame(['first', 'replacement'], $seen);
        });
    }

    public function testCommitObserverCanBeginANewTransactionWithoutItBeingClearedOnReturn(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $this->remember($connection);
            $once = false;
            $connection->onQuery(function () use ($connection, &$once): void {
                if (!$once) {
                    $once = true;
                    $this->assertFalse($connection->isInTransaction);
                    $this->assertTrue((new FastDb())->begin($connection));
                }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertTrue((new FastDb())->commit($connection));
            $this->assertTrue($connection->isInTransaction);
            $this->assertTrue($connection->getTransactionContext()->isActive());
        }, true);
    }

    public function testCommitWithoutAnActiveTransactionDoesNotFinishOneStartedByItsObserver(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $once = false;
            $connection->onQuery(function () use ($connection, &$once): void {
                if (!$once) { $once = true; $connection->beginTransaction(TransactionStartFlags::None); }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertTrue($connection->commitTransaction(TransactionCompletionFlags::NoChainNoRelease));
            $this->assertTrue($connection->isInTransaction);
        });
    }

    public function testObserverQueryErrorDoesNotRollbackANewerTransactionTwice(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $once = false; $failure = new \EasySwoole\Mysqli\Exception\Exception('observer error', 1213);
            $connection->onQuery(function () use ($connection, &$once, $failure): void {
                if (!$once) { $once = true; $connection->beginTransaction(TransactionStartFlags::None); throw $failure; }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            try { $connection->commitTransaction(TransactionCompletionFlags::NoChainNoRelease); $this->fail('Expected observer error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($connection->isInTransaction);
            $this->assertTrue($connection->getTransactionContext()->isActive());
        }, true);
    }

    public function testBeginObserverCanRollbackWithoutTheFacadeRestoringTheActiveFlag(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $once = false;
            $connection->onQuery(function () use ($connection, &$once): void {
                if (!$once) { $once = true; $this->assertTrue((new FastDb())->rollback($connection)); }
            });
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue((new FastDb())->begin($connection));
            $this->assertFalse($connection->isInTransaction);
        });
    }

    public function testClosingAnUnfinishedTransactionRestoresAndInvalidatesItsContext(): void
    {
        $this->withConnection(function (Connection $connection): void {
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
        $this->withConnection(function (Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
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

    public function testReleaseCommitsAndClosesBeforeAnObserverThrows(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer): void {
            $entity = $this->remember($connection);
            $token = $connection->getTransactionContext()->token();
            $failure = new \RuntimeException('release observer');
            $connection->onQuery(function () use ($connection, $failure): void {
                $this->assertFalse($connection->isInTransaction);
                $this->assertNull($connection->mysqlClient());
                throw $failure;
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            try { $connection->commitTransaction(TransactionCompletionFlags::Release); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($token->wasRolledBack());
            $this->assertSame(7, $entity->value);
            $this->assertFalse($connection->isInTransaction);
            $this->assertNull($connection->mysqlClient());
        }, true);
    }

    public function testDisconnectedRestoreEndsLocalContextWithoutSendingRollback(): void
    {
        $this->withConnection(function (Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
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

    private function execute(Connection $connection, string $mode, string $sql): void
    {
        if ($mode === 'raw') { $connection->rawQuery($sql); }
        else { $connection->query((new QueryBuilder())->raw($sql)); }
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
            try { $test($connection, $peer, $protocol); }
            finally { $connection->close(); $peer->close(); }
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
