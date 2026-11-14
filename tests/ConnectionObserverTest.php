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
use Swoole\Coroutine\Channel;
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
    public function testRollbackStateIsSynchronizedBeforeAGlobalObserverThrows(string $mode): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode): void {
            $failure = new \RuntimeException('observer failed');
            $db->setOnQuery(function (QueryResult $result) use ($connection, $failure): void {
                $this->assertTrue($result->getResult());
                $this->assertFalse($connection->isInTransaction());
                throw $failure;
            });
            $this->queueQuery($peer, $mode, $this->ok());
            try { $this->execute($db, $mode, 'ROLLBACK'); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction());
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
            $this->assertSame('DO 1', $result->getLastPrepareQuery());
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
            $this->assertSame('DO 1', $completed[0]->getLastPrepareQuery());
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
            $this->assertSame('DO outer_query', $result->getLastPrepareQuery());
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

    public static function directOperations(): array
    {
        return [['raw', false], ['prepared', false], ['raw', true], ['prepared', true], ['restore', false]];
    }

    #[DataProvider('directOperations')]
    public function testDirectOperationsReturnDriverResultsAndMetadata(string $mode, bool $fails): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode, $fails): void {
            $observations = new Channel(1);
            Coroutine::create(static function () use ($connection, $peer, $mode, $fails, $observations): void {
                $commands = [];
                $read = static function () use ($peer, &$commands): void {
                    $header = $peer->recvAll(4, 0.1);
                    if (!is_string($header) || strlen($header) !== 4) { throw new \RuntimeException('Missing command header'); }
                    $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
                    $commands[] = $peer->recvAll($length, 0.1);
                };
                $send = static function (string $payload) use ($peer): void {
                    $packet = substr(pack('V', strlen($payload)), 0, 3) . "\1" . $payload;
                    if ($peer->sendAll($packet, 0.1) !== strlen($packet)) { throw new \RuntimeException('Cannot send response'); }
                };
                try {
                    if ($mode === 'prepared') { $read(); $send("\0" . pack('VvvCv', 7, 0, 0, 0, 0)); }
                    $read();
                    $send($fails ? "\xff" . pack('v', 1064) . '#42000direct failure' : "\0\3\x29\2\0\0\0");
                    if ($mode === 'prepared') { $read(); }
                    $observations->push($commands);
                } catch (\Throwable $error) { $observations->push($error); }
            });
            try {
                $value = match ($mode) {
                    'raw' => $connection->rawQuery('DO direct_query'),
                    'prepared' => $connection->query((new QueryBuilder())->raw('DO direct_query')),
                    'restore' => $connection->objectRestore(),
                };
                $this->assertFalse($fails);
                if ($mode !== 'restore') { $this->assertTrue($value); }
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                $this->assertTrue($fails);
                $this->assertSame(1064, $error->getCode());
            }
            $observed = $observations->pop(0.2);
            $this->assertIsArray($observed);
            $commands = $observed;
            $this->assertSame($mode === 'prepared' ? 3 : 1, count($commands));
            $this->assertSame($fails ? null : 3, $connection->getLastAffectRows());
            $this->assertSame($fails ? null : 41, $connection->getLastInsertId());
        }, $mode === 'restore');
    }

    #[DataProvider('queryModes')]
    public function testCaughtDirectFactoryErrorsDoNotAffectTheOuterQuery(string $mode): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode): void {
            $completed = []; $logged = [];
            $db->setOnQuery(static function (QueryResult $result) use (&$logged): void { $logged[] = $result; });
            $this->queueQuery($peer, $mode, "\xff" . pack('v', 1064) . '#42000direct failure');
            $this->queueQuery($peer, 'prepared', $this->ok(2, 1, 11));
            $result = $db->query(function (QueryBuilder $builder) use ($connection, $mode): void {
                try {
                    $mode === 'raw' ? $connection->rawQuery('DO inner_query') : $connection->query($builder->raw('DO inner_query'));
                    $this->fail('Expected direct SQL failure');
                } catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1064, $error->getCode()); }
                $builder->raw('DO outer_query');
            }, onComplete: static function (QueryResult $result) use (&$completed): void { $completed[] = $result; });
            $this->assertSame([$result], $completed);
            $this->assertSame([$result], $logged);
            $this->assertNull($result->getException());
            $this->assertSame('DO outer_query', $result->getLastPrepareQuery());
            $this->assertSame(1, $result->getAffectedRows());
            $this->assertSame(11, $result->getInsertId());
        });
    }

    public function testCompletionDirectReentryKeepsTheOuterResultAndLatestConnectionMetadata(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->queueQuery($peer, 'prepared', $this->ok(2, 1, 11));
            $this->queueQuery($peer, 'raw', $this->ok(2, 2, 22));
            $result = $db->query((new QueryBuilder())->raw('DO outer_query'), onComplete: function (QueryResult $result, Connection $client): void {
                $this->assertTrue($client->rawQuery('DO inner_query'));
                $this->assertSame(2, $client->getLastAffectRows());
                $this->assertSame(22, $client->getLastInsertId());
                $this->assertSame(1, $result->getAffectedRows());
            });
            $this->assertSame(1, $result->getAffectedRows());
            $this->assertSame(11, $result->getInsertId());
            $this->assertSame(2, $connection->getLastAffectRows());
            $this->assertSame(22, $connection->getLastInsertId());
        });
    }

    public function testCompletionFailureDoesNotBecomeAConnectionTimeoutOrInvalidateRecentActivity(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \EasySwoole\Mysqli\Exception\TimeoutException('business completion failed', 110);
            $logged = null;
            $db->setOnQuery(static function (QueryResult $result) use (&$logged): void { $logged = $result; });
            $this->queueQuery($peer, 'raw', $this->ok(2, 1, 11));
            try {
                $db->rawQuery('DO 1', onComplete: static function () use ($failure): void { throw $failure; });
                $this->fail('Expected completion error');
            } catch (\Throwable $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($logged->getResult());
            $this->assertNull($logged->getException());
            $this->assertSame(11, $logged->getInsertId());
            $this->assertTrue($connection->beforeUse());
            $this->assertSame("\x05\0\0\0", $peer->recvAll(4, 0.1));
            $this->assertSame("\x03DO 1", $peer->recvAll(5, 0.1));
            $this->assertFalse($peer->recv(1, 0.001), 'Callback failure must not trigger a health probe.');
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
            $once = false;
            $db->setOnQuery(function () use ($db, $connection, &$once): void {
                if (!$once) { $once = true; $this->assertFalse($connection->isInTransaction()); $this->assertTrue($db->begin()); }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertTrue($db->commit());
            $this->assertTrue($connection->isInTransaction());
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
            $this->assertTrue($connection->isInTransaction());
        });
    }

    public function testGlobalObserverErrorDoesNotChangeANewerTransactionState(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $once = false; $failure = new \EasySwoole\Mysqli\Exception\Exception('observer error', 1213);
            $db->setOnQuery(static function () use ($db, &$once, $failure): void {
                if (!$once) { $once = true; $db->begin(); throw $failure; }
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->queueQuery($peer, 'raw', $this->ok(1));
            try { $db->commit(); $this->fail('Expected observer error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame($failure, $error); }
            $this->assertTrue($connection->isInTransaction());
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
            $this->assertFalse($connection->isInTransaction());
        });
    }

    public function testConnectionObserverRegistrationRejectsPayloadWithoutReplacingTheDispatcher(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $called = false;
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
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    public function testDirectRawCommitSynchronizesStateWithoutCallingTheFacadeObserver(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $calls = 0;
            $db->setOnQuery(static function () use (&$calls): void { $calls++; });
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue($connection->rawQuery('COMMIT'));
            $this->assertFalse($connection->isInTransaction());
            $this->assertSame(0, $calls);
        }, true);
    }

    public function testClosingAnUnfinishedTransactionClearsItsActiveFlag(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection): void {
            $this->assertTrue($connection->close());
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    public function testRejectedCloseKeepsTheActiveTransactionFlag(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $tickProperty = new \ReflectionProperty(Connection::class, 'lastActiveTick');
            $tick = hrtime(true); $tickProperty->setValue($connection, $tick);
            $busy = new \ReflectionProperty($protocol, 'busy'); $busy->setValue($protocol, true);
            try {
                try { $connection->close(); $this->fail('Expected concurrent close rejection'); }
                catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertStringContainsString('Concurrent', $error->getMessage()); }
                $this->assertTrue($connection->isInTransaction());
                $this->assertTrue($protocol->isConnected());
                $this->assertSame($tick, $tickProperty->getValue($connection));
            } finally { $busy->setValue($protocol, false); }
        }, true);
    }

    public function testReleaseCommitsAndClosesBeforeAGlobalObserverThrows(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $failure = new \RuntimeException('release observer');
            $db->setOnQuery(function () use ($connection, $failure): void {
                $this->assertFalse($connection->isInTransaction());
                $this->assertNull($connection->mysqlClient());
                throw $failure;
            });
            $this->queueQuery($peer, 'raw', $this->ok());
            try { $db->commit(flags: TransactionCompletionFlags::Release); $this->fail('Expected observer error'); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertFalse($connection->isInTransaction());
            $this->assertNull($connection->mysqlClient());
        }, true);
    }

    public function testDisconnectedRestoreClearsTheTransactionWithoutSendingRollback(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer, ProtocolConnection $protocol): void {
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol); $stream->close();
            $connection->objectRestore();
            $this->assertFalse($connection->isInTransaction());
            $this->assertNull($connection->mysqlClient());
            $data = $peer->recv(1, 0.01);
            $this->assertFalse(is_string($data) && $data !== '', 'Disconnected cleanup must not send ROLLBACK');
        }, true);
    }

    public static function sqlErrorModes(): array
    {
        return [['raw', 1064, true], ['prepared', 1064, true], ['raw', 1205, true],
            ['prepared', 1205, true], ['raw', 1213, false], ['prepared', 1213, false]];
    }

    #[DataProvider('sqlErrorModes')]
    public function testOnlyDeadlocksClearTheActiveFlagOnSqlErrors(string $mode, int $code, bool $active): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode, $code, $active): void {
            $this->queueQuery($peer, $mode, "\xff" . pack('v', $code) . '#HY000statement failed');
            try { $this->execute($db, $mode, 'DO 1'); $this->fail('Expected SQL error'); }
            catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame($code, $error->getCode()); }
            $this->assertSame($active, $connection->isInTransaction());
            $this->assertTrue($connection->mysqlClient()->isConnected());
        }, true);
    }

    public static function rawBoundaries(): array
    {
        return [['COMMIT'], ['ROLLBACK'], ['ALTER TABLE scope_rows ADD COLUMN extra INT']];
    }

    #[DataProvider('rawBoundaries')]
    public function testRawSqlTrustsTheProtocolTransactionState(string $sql): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($sql): void {
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertTrue($connection->rawQuery($sql));
            $this->assertFalse($connection->isInTransaction());
            $this->assertFalse($connection->mysqlClient()->inTransaction());
        }, true);
    }

    public static function completionModes(): array
    {
        return [['commit', TransactionCompletionFlags::Chain, true],
            ['rollback', TransactionCompletionFlags::Chain, true],
            ['commit', TransactionCompletionFlags::NoChainNoRelease, false],
            ['rollback', TransactionCompletionFlags::Release, false]];
    }

    #[DataProvider('completionModes')]
    public function testTypedCompletionPreservesChainAndReleaseState(string $operation, TransactionCompletionFlags $flags, bool $active): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($operation, $flags, $active): void {
            $this->queueQuery($peer, 'raw', $this->ok($active ? 1 : 2));
            $this->assertTrue($connection->{$operation . 'Transaction'}($flags));
            $this->assertSame($active, $connection->isInTransaction());
            $this->assertSame($flags->releasesConnection(), $connection->mysqlClient() === null);
        }, true);
    }

    public function testInheritedStreamsExposeActualTransactionState(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->assertSame([], iterator_to_array($connection->rawIterate('START TRANSACTION')));
            $this->assertTrue($connection->isInTransaction());
            $this->assertNotNull((new \ReflectionProperty(Connection::class, 'lastActiveTick'))->getValue($connection));
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertSame([], iterator_to_array($connection->rawIterate('ROLLBACK')));
            $this->assertFalse($connection->isInTransaction());
        });
    }

    #[DataProvider('queryModes')]
    public function testStreamStartupDeadlockLetsTheNextHelperStartANewTransaction(string $mode): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($mode): void {
            $this->queueQuery($peer, $mode, "\xff" . pack('v', 1213) . '#40001deadlock');
            try {
                $mode === 'raw' ? $connection->rawIterate('SELECT 1')
                    : $connection->iterate((new QueryBuilder())->raw('SELECT 1'));
                $this->fail('Expected stream startup deadlock');
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) { $this->assertSame(1213, $error->getCode()); }
            $this->assertFalse($connection->isInTransaction());
            $this->assertTrue($connection->mysqlClient()->isConnected());
            $commands = [];
            $db->setOnQuery(static function (QueryResult $result) use (&$commands): void { $commands[] = $result->getRawSql(); });
            $this->queueQuery($peer, 'raw', $this->ok(1));
            $this->queueQuery($peer, 'raw', $this->ok());
            $this->assertSame('done', $db->transaction(static fn (): string => 'done'));
            $this->assertSame(['START TRANSACTION', 'COMMIT AND NO CHAIN NO RELEASE'], $commands);
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    public function testInterruptedStreamReadsTheDisconnectedProtocolStateWithoutASuccessCallback(): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer): void {
            $this->reply($peer, "\1");
            $column = '';
            foreach (['def', '', '', '', 'id', 'id'] as $part) { $column .= chr(strlen($part)) . $part; }
            $column .= "\x0c" . pack('vV', 33, 255) . "\x08" . pack('vC', 0, 0) . "\0\0";
            $this->reply($peer, $column, 2);
            $this->reply($peer, "\xfe\0\0\1\0", 3);
            $stream = $connection->rawIterate('SELECT id');
            $this->assertTrue($connection->isInTransaction());
            $peer->close();
            try { iterator_to_array($stream); $this->fail('Expected stream read failure'); }
            catch (\EasySwoole\Mysqli\Exception\Exception) { $this->addToAssertionCount(1); }
            $this->assertFalse($connection->mysqlClient()->isConnected());
            $this->assertFalse($connection->isInTransaction());
        }, true);
    }

    public static function healthErrorModes(): array { return [[1064, true], [1213, false]]; }

    #[DataProvider('healthErrorModes')]
    public function testHealthErrorsReadTheActualProtocolState(int $code, bool $active): void
    {
        $this->withConnection(function (FastDb $db, Connection $connection, Socket $peer) use ($code, $active): void {
            $this->queueQuery($peer, 'raw', "\xff" . pack('v', $code) . '#HY000health query failed');
            $this->assertFalse($connection->beforeUse());
            $this->assertSame($active, $connection->isInTransaction());
        }, true);
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
            $connection = new Connection($config); $connection->connectionName = 'default';
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
    private function reply(Socket $peer, string $payload, int $sequence = 1): void { $this->assertSame(strlen($payload) + 4, $peer->sendAll(substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload, 0.1)); }
}
