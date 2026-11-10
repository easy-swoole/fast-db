<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Config as FastDbConfig;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class TransactionScopeTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private array $connections = [];
    private array $observers = [];
    private array $files = [];
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite verifies real transaction visibility without MySQL.'); }
        $this->db = new FastDb();
        foreach (['a', 'b'] as $name) {
            $file = tempnam(sys_get_temp_dir(), 'fastdb-scope-' . $name . '-');
            if ($file === false) { throw new \RuntimeException('Cannot allocate SQLite fixture'); }
            $this->files[] = $file;
            $database = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $database->exec('CREATE TABLE scope_ledger (id INTEGER PRIMARY KEY, amount INTEGER)');
            $connection = new TransactionScopeSqliteClient($database);
            $connection->connectionName = $name;
            $this->connections[$name] = $connection;
            $this->observers[$name] = new \PDO('sqlite:' . $file, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->db->addDb(new FastDbConfig(), $name);
        }
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $this->connections]);
        (new \ReflectionProperty(FastDb::class, 'selectConnection'))->setValue($this->db, [Coroutine::getCid() => 'a']);
        $this->db->setOnQuery(function (QueryResult $result): void { $this->events[] = $result; });
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->singleton !== null) {
            foreach ($this->connections as $connection) {
                if ($connection->database->inTransaction()) { $connection->database->rollBack(); }
                $connection->finishTransaction(false);
            }
            $this->db->setOnQuery(static function (): void {});
            (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
            $this->singleton->setValue(null, $this->originalInstance);
        }
        $this->observers = [];
        $this->connections = [];
        foreach ($this->files as $file) { if (is_file($file)) { unlink($file); } }
    }

    private function insert(int $amount): void { $this->db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (' . $amount . ')'); }
    private function amounts(string $name, bool $outside = true): array
    {
        return ($outside ? $this->observers[$name] : $this->connections[$name]->database)
            ->query('SELECT amount FROM scope_ledger ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }
    private function commands(string $name): array
    {
        $commands = [];
        foreach ($this->connections[$name]->queries as $query) {
            if (preg_match('/\A(START|COMMIT|ROLLBACK)\b/', $query['sql'], $match)) { $commands[] = $match[1]; }
        }
        return $commands;
    }

    public static function managedLifecycleOperations(): array { return [['recycle'], ['reset']]; }

    #[DataProvider('managedLifecycleOperations')]
    public function testManagedLifecycleRejectionPreservesEveryBorrowingAndTheOpenTransaction(string $operation): void
    {
        $db = new FastDb();
        $pools = [];
        foreach (['a', 'b'] as $name) {
            $config = new FastDbConfig(['maxObjectNum' => 1, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
            $db->addDb($config, $name);
            $pools[$name] = new TransactionScopeLifecyclePool($config, $this->connections[$name]);
        }
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, $pools);
        try {
            // 先通过 FastDb 借用 B：拒绝回收前必须检查全部连接，不能提前归还任何客户端。
            $db->selectConnection('b');
            $db->rawQuery('SELECT 1');
            $db->selectConnection('a');
            $db->transaction(function () use ($db, $pools, $operation): void {
                $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (10)');
                $client = $db->currentConnection();
                $context = $db->getTransactionContext();
                $version = $context->version();
                try {
                    if ($operation === 'recycle') { $db->recycleContext(false); }
                    else { $db->reset(); }
                    $this->fail('Managed clients cannot be recycled or reset from their callback');
                } catch (RuntimeError) { $this->addToAssertionCount(1); }
                foreach ($pools as $pool) {
                    $this->assertSame(1, $pool->borrows);
                    $this->assertSame(0, $pool->recycles);
                    $this->assertSame(0, $pool->resets);
                }
                $this->assertSame($client, $db->currentConnection());
                $this->assertSame($context, $db->getTransactionContext());
                $this->assertSame($version, $context->version());
                $this->assertTrue($client->database->inTransaction());
                $this->assertSame([10], $this->amounts('a', false));
                $this->assertSame([], $this->amounts('a'));
                $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (20)');
            });
            $this->assertSame([10, 20], $this->amounts('a'));
            $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
            $db->recycleContext();
            foreach ($pools as $pool) { $this->assertSame(1, $pool->recycles); }
        } finally { $db->recycleContext(false); $db->reset(); }
    }

    public function testCoroutineExitExceptionRollsBackBeforeReturningTheClientToThePool(): void
    {
        $config = new FastDbConfig(['maxObjectNum' => 1, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $db = (new FastDb())->addDb($config, 'a');
        $pool = new TransactionScopeLifecyclePool($config, $this->connections['a']);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['a' => $pool]);
        $finished = new Coroutine\Channel(1);
        $context = null;
        $scope = null;
        $businessFinally = false;
        $exitCaught = false;
        try {
            Coroutine::create(function () use ($db, $finished, &$context, &$scope, &$businessFinally, &$exitCaught): void {
                // FastDb 后登记的 defer 必须先完成，再发送此通知。
                Coroutine::defer(static fn() => $finished->push(true));
                $db->selectConnection('a');
                try {
                    $db->transaction(function () use ($db, &$context, &$scope, &$businessFinally): void {
                        $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (10)');
                        $context = \WeakReference::create($db->getTransactionContext());
                        $scope = \WeakReference::create($db->currentConnection()->__getTransactionScope());
                        try { exit(0); }
                        finally { $businessFinally = true; }
                    });
                } catch (\Swoole\ExitException) { $exitCaught = true; }
            });
            $this->assertTrue($finished->pop(2));
            $this->assertTrue($exitCaught);
            $this->assertTrue($businessFinally, 'Swoole converts PHP exit to ExitException and runs finally.');
            $this->assertSame(1, $pool->borrows);
            $this->assertSame(1, $pool->recycles);
            $this->assertSame([['closed' => false, 'active' => false, 'scope' => null]], $pool->recycleStates);
            $this->assertSame([], $this->amounts('a'));
            $this->assertNull($context->get());
            $this->assertNull($scope->get());
            $this->assertNull($db->currentConnection());
            $this->assertNull($db->getQueryStack());
        } finally { $db->reset(); }
    }

    public function testFailedRollbackAndClosePreserveTheBusinessErrorAndBlockReuseUntilCloseSucceeds(): void
    {
        $client = new TransactionScopeCleanupFailureClient($this->connections['a']->database);
        $config = new FastDbConfig(['maxObjectNum' => 1, 'minObjectNum' => 0, 'intervalCheckTime' => 0]);
        $db = (new FastDb())->addDb($config, 'a');
        $pool = new TransactionScopeLifecyclePool($config, $client);
        (new \ReflectionProperty(FastDb::class, 'pools'))->setValue($db, ['a' => $pool]);
        $db->selectConnection('a');
        $failure = new \RuntimeException('business error must survive failed cleanup');
        try {
            try {
                $db->transaction(function () use ($db, $failure): void {
                    $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (10)');
                    throw $failure;
                });
                $this->fail('The original failure must propagate');
            } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $scope = $client->__getTransactionScope();
            $this->assertNotNull($scope);
            $this->assertSame(0, $scope->depth);
            $this->assertTrue($scope->rollbackOnly);
            $this->assertTrue($scope->unusable);
            $this->assertTrue($scope->isCurrent($client));
            $this->assertSame([], $this->amounts('a'));
            $before = count($client->queries);
            try { $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (99)'); $this->fail('An unusable transaction cannot send more SQL'); }
            catch (RuntimeError) { $this->addToAssertionCount(1); }
            $called = false;
            try { $db->transaction(static function () use (&$called): void { $called = true; }); $this->fail('An unusable transaction cannot be joined'); }
            catch (RuntimeError) { $this->addToAssertionCount(1); }
            $this->assertFalse($called);
            $this->assertSame($before, count($client->queries));
            $client->allowClose = true;
            $this->assertTrue($client->close());
            $this->assertNull($client->__getTransactionScope());
            $db->transaction(static fn() => $db->rawQuery('INSERT INTO scope_ledger (amount) VALUES (20)'));
            $this->assertSame([20], $this->amounts('a'));
        } finally { $client->allowClose = true; $client->close(); $db->recycleContext(false); $db->reset(); }
    }

    public function testOwnedTransactionCommitsOnceAndReturnsTheExactCallbackValue(): void
    {
        $value = new \stdClass();
        $result = $this->db->transaction(function () use ($value): object {
            $this->insert(10);
            $this->assertTrue($this->connections['a']->database->inTransaction());
            $this->assertSame([10], $this->amounts('a', false));
            $this->assertSame([], $this->amounts('a'), 'Independent observers cannot see uncommitted rows.');
            return $value;
        });
        $this->assertSame($value, $result);
        $this->assertSame([10], $this->amounts('a'));
        $this->assertSame([], $this->amounts('b'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
        foreach ($this->events as $event) { $this->assertSame('a', $event->getConnectionName()); }
    }

    public function testFalseIsANormalCommittedReturnValueAndTheCallbackReceivesTheFacade(): void
    {
        $this->assertFalse($this->db->transaction(function (FastDb $db): bool {
            $this->assertSame($this->db, $db);
            $this->insert(1);
            return false;
        }));
        $this->assertSame([1], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
    }

    public function testFlagsAndTimeoutAreForwardedToEachControlOperation(): void
    {
        $this->assertSame('ok', $this->db->transaction(static fn(): string => 'ok', TransactionStartFlags::ReadWrite, 0.25));
        $controls = $this->connections['a']->queries;
        $this->assertCount(2, $controls);
        $this->assertSame(TransactionStartFlags::ReadWrite->toSql(), $controls[0]['sql']);
        $this->assertSame(0.25, $controls[0]['timeout']);
        $this->assertSame(0.25, $controls[1]['timeout']);
    }

    public function testTimeoutDoesNotImposeADeadlineOnTheBusinessCallback(): void
    {
        $this->assertSame('slow body', $this->db->transaction(function (): string {
            if (Coroutine::getCid() >= 0) { Coroutine::sleep(0.005); } else { usleep(5000); }
            $this->insert(1);
            return 'slow body';
        }, timeout: 0.001));
        $this->assertSame([1], $this->amounts('a'));
        $this->assertSame(0.001, $this->connections['a']->queries[0]['timeout']);
        $this->assertSame(0.001, $this->connections['a']->queries[2]['timeout']);
    }

    public function testCallbackExceptionRollsBackAndPreservesTheOriginalError(): void
    {
        $failure = new \RuntimeException('business failed');
        try { $this->db->transaction(function () use ($failure): void { $this->insert(10); throw $failure; }); $this->fail('Callback exception must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testNestedSameConnectionJoinsWithoutBeginningOrCommittingEarly(): void
    {
        $result = $this->db->transaction(function (): string {
            $this->insert(1);
            $this->assertSame('inner-result', $this->db->transaction(function (): string { $this->insert(2); return 'inner-result'; }));
            $this->assertSame(['START'], $this->commands('a'));
            $this->assertTrue($this->connections['a']->database->inTransaction());
            $this->assertSame([1, 2], $this->amounts('a', false));
            $this->assertSame([], $this->amounts('a'));
            return 'outer-result';
        });
        $this->assertSame('outer-result', $result);
        $this->assertSame([1, 2], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
    }

    public function testIndependentBFailureCaughtByADoesNotPoisonATransaction(): void
    {
        $failure = new \RuntimeException('b failed');
        $this->assertSame('a committed', $this->db->transaction(function () use ($failure): string {
            $this->insert(1);
            try {
                $this->db->withConnection('b', function () use ($failure): void {
                    $this->db->transaction(function () use ($failure): void { $this->insert(99); throw $failure; });
                });
                $this->fail('B must fail');
            } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->assertSame('a', $this->db->selectConnection());
            $this->assertTrue($this->connections['a']->database->inTransaction());
            $this->insert(2);
            return 'a committed';
        }));
        $this->assertSame([1, 2], $this->amounts('a'));
        $this->assertSame([], $this->amounts('b'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('b'));
    }

    public function testAtoBtoAJoinsTheOriginalAWhileBCommitsIndependently(): void
    {
        $this->db->transaction(function (): void {
            $this->insert(1);
            $this->db->withConnection('b', function (): void {
                $this->db->transaction(function (): void {
                    $this->insert(20);
                    $this->db->withConnection('a', function (): void {
                        $this->db->transaction(function (): void { $this->insert(2); });
                        $this->assertSame(['START'], $this->commands('a'));
                        $this->assertSame([], $this->amounts('a'));
                    });
                    $this->assertSame('b', $this->db->selectConnection());
                    $this->assertSame([], $this->amounts('b'));
                });
            });
            $this->assertSame('a', $this->db->selectConnection());
            $this->assertSame([20], $this->amounts('b'));
            $this->assertSame([], $this->amounts('a'));
        });
        $this->assertSame([1, 2], $this->amounts('a'));
        $this->assertSame([20], $this->amounts('b'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('b'));
    }

    public function testCaughtInnerSameConnectionFailureMakesTheOwnerRollbackAndThrowClearly(): void
    {
        $innerFailure = new \RuntimeException('inner failed');
        try {
            $this->db->transaction(function () use ($innerFailure): void {
                $this->insert(1);
                try { $this->db->transaction(function () use ($innerFailure): void { $this->insert(2); throw $innerFailure; }); }
                catch (\RuntimeException $error) { $this->assertSame($innerFailure, $error); }
                $this->insert(3);
                $this->assertSame([], $this->amounts('a'));
            });
            $this->fail('The outer owner cannot commit a rollback-only transaction');
        } catch (RuntimeError $error) {
            $this->assertStringContainsString('rollback', strtolower($error->getMessage()));
        }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
        $this->assertSame('new transaction', $this->db->transaction(function (): string { $this->insert(4); return 'new transaction'; }));
        $this->assertSame([4], $this->amounts('a'), 'Rollback-only state must not leak into the next generation.');
    }

    public function testJoiningAManualTransactionDoesNotCommitItsSuccessfulCallback(): void
    {
        $this->assertTrue($this->db->begin());
        $this->assertSame('joined', $this->db->transaction(function (): string { $this->insert(1); return 'joined'; }));
        $this->assertSame([], $this->amounts('a'));
        $this->assertTrue($this->connections['a']->database->inTransaction());
        $this->assertSame(['START'], $this->commands('a'));
        $this->assertTrue($this->db->commit());
        $this->assertSame([1], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
    }

    public function testFailedJoinMarksAnExistingManualTransactionSoManualCommitCannotSucceed(): void
    {
        $this->assertTrue($this->db->begin());
        $failure = new \RuntimeException('joined business failure');
        try { $this->db->transaction(function () use ($failure): void { $this->insert(1); throw $failure; }); $this->fail('Joined callback must fail'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        try { $this->db->commit(); $this->fail('A rollback-only manual transaction must refuse commit'); }
        catch (RuntimeError $error) { $this->assertStringContainsString('rollback', strtolower($error->getMessage())); }
        $this->assertSame(['START'], $this->commands('a'));
        $this->assertTrue($this->connections['a']->database->inTransaction());
        $this->assertSame([1], $this->amounts('a', false));
        $this->assertSame([], $this->amounts('a'));
        $this->assertTrue($this->db->rollback());
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->db->transaction(function (): void { $this->insert(2); });
        $this->assertSame([2], $this->amounts('a'));
    }

    public static function invalidJoinOptions(): array
    {
        return [
            [TransactionStartFlags::ReadWrite, null],
            [TransactionStartFlags::None, 0.25],
            [TransactionStartFlags::ReadOnly, 0.25],
            [TransactionStartFlags::None, 0.0],
        ];
    }

    #[DataProvider('invalidJoinOptions')]
    public function testJoinOptionsAreRejectedBeforeTheBodyWithoutPoisoningAManualTransaction(TransactionStartFlags $flags, ?float $timeout): void
    {
        $this->assertTrue($this->db->begin());
        $calls = 0;
        try { $this->db->transaction(static function () use (&$calls): void { $calls++; }, $flags, $timeout); $this->fail('Join options cannot start a different transaction mode'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, $calls);
        $this->assertSame(['START'], $this->commands('a'));
        $this->assertTrue($this->connections['a']->database->inTransaction());
        $this->assertTrue($this->db->commit());
    }

    public static function manualCommands(): array { return [['begin'], ['commit'], ['rollback']]; }

    #[DataProvider('manualCommands')]
    public function testManualControlOfTheScopedActualConnectionIsRejectedBeforeSql(string $command): void
    {
        try {
            $this->db->transaction(function () use ($command): void {
                $this->insert(1);
                $this->db->selectConnection('b');
                $before = count($this->connections['a']->queries);
                try {
                    $this->db->{$command}($this->connections['a']);
                    $this->fail('Manual ' . $command . ' cannot alter the enclosing transaction scope');
                } catch (RuntimeError $error) {
                    $this->assertCount($before, $this->connections['a']->queries);
                    throw $error;
                }
            });
            $this->fail('The rejected manual command must propagate');
        } catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
    }

    public function testSuccessCompletesTheEntryConnectionEvenIfTheCallbackSelectedB(): void
    {
        $this->assertSame('returned', $this->db->transaction(function (): string {
            $this->insert(1);
            $this->db->selectConnection('b');
            return 'returned';
        }));
        $this->assertSame([1], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testFailureRollsBackTheEntryConnectionEvenIfTheCallbackSelectedB(): void
    {
        $failure = new \RuntimeException('a failed after selection changed');
        try {
            $this->db->transaction(function () use ($failure): void { $this->insert(1); $this->db->selectConnection('b'); throw $failure; });
            $this->fail('Callback exception must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testUncaughtNestedSameConnectionFailurePreservesTheBusinessException(): void
    {
        $failure = new \RuntimeException('deep failure');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->insert(1);
                $this->db->transaction(static function () use ($failure): void { throw $failure; });
            });
            $this->fail('Business exception must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
    }

    public function testRollbackOnlyOwnerRestoresEntityBaselinesSoTheCurrentValuesCanBeRetried(): void
    {
        $this->connections['a']->database->exec('INSERT INTO scope_ledger VALUES (1,0)');
        $entity = new TransactionScopeLedgerRecord(['id' => 1, 'amount' => 0]);
        try {
            $this->db->transaction(function () use ($entity): void {
                $entity->amount = 10;
                $this->assertTrue($entity->update());
                try { $this->db->transaction(static function (): void { throw new \RuntimeException('joined failure'); }); }
                catch (\RuntimeException) { /* 外层业务捕获了异常，事务仍然不能提交。 */ }
            });
            $this->fail('A rollback-only owner must fail');
        } catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertSame([0], $this->amounts('a'));
        $this->assertSame(10, $entity->amount);
        $this->assertTrue($entity->update());
        $this->assertSame([10], $this->amounts('a'), 'Rollback must restore the entity comparison baseline, not consume the retry.');
    }

    public function testBeginReturningFalseThrowsBeforeTheCallbackWithoutPretendingToOwnATransaction(): void
    {
        $this->connections['a']->failCommands['START'] = false;
        $calls = 0;
        try { $this->db->transaction(static function () use (&$calls): void { $calls++; }); $this->fail('Failed begin cannot run the callback'); }
        catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertSame(0, $calls);
        $this->assertSame(['START'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testAnObserverThrowingAfterSuccessfulBeginStillCleansUpTheOwnedTransaction(): void
    {
        $failure = new \RuntimeException('begin observer failed');
        $this->db->setOnQuery(function (QueryResult $result) use ($failure): void {
            $this->events[] = $result;
            if (str_starts_with($result->getRawSql() ?? '', 'START TRANSACTION')) { throw $failure; }
        });
        $calls = 0;
        try { $this->db->transaction(static function () use (&$calls): void { $calls++; }); $this->fail('Begin observer failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(0, $calls);
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testCommitReturningFalseRollsBackAndDoesNotRetryCommit(): void
    {
        $this->connections['a']->failCommands['COMMIT'] = false;
        try { $this->db->transaction(function (): void { $this->insert(1); }); $this->fail('Failed commit must throw'); }
        catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testACommitExceptionRemainsTheOriginalExceptionAfterRollbackCleanup(): void
    {
        $failure = new \RuntimeException('commit failed before completion');
        $this->connections['a']->failCommands['COMMIT'] = $failure;
        try { $this->db->transaction(function (): void { $this->insert(1); }); $this->fail('Commit failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testPhpErrorsAlsoRollbackRatherThanBeingTreatedAsSuccessfulReturns(): void
    {
        $failure = new \Error('business type failure');
        try { $this->db->transaction(function () use ($failure): void { $this->insert(1); throw $failure; }); $this->fail('PHP Error must propagate'); }
        catch (\Error $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testBusinessExceptionWinsOverRollbackObserverFailure(): void
    {
        $business = new \RuntimeException('business error');
        $observer = new \RuntimeException('rollback observer error');
        $this->db->setOnQuery(function (QueryResult $result) use ($observer): void {
            $this->events[] = $result;
            if (str_starts_with($result->getRawSql() ?? '', 'ROLLBACK')) { throw $observer; }
        });
        try { $this->db->transaction(function () use ($business): void { $this->insert(1); throw $business; }); $this->fail('Business failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($business, $error); }
        $this->assertSame([], $this->amounts('a'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }

    public function testSuccessfulCommitObserverFailureDoesNotRollbackAnAlreadyCommittedTransaction(): void
    {
        $failure = new \RuntimeException('commit observer failed');
        $this->db->setOnQuery(function (QueryResult $result) use ($failure): void {
            $this->events[] = $result;
            if (str_starts_with($result->getRawSql() ?? '', 'COMMIT')) { throw $failure; }
        });
        try { $this->db->transaction(function (): void { $this->insert(1); }); $this->fail('Commit observer exception must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([1], $this->amounts('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertFalse($this->connections['a']->database->inTransaction());
    }
}

class TransactionScopeLedgerRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public ?int $amount;
    public function tableName(): string { return 'scope_ledger'; }
}

/** 使用真实 SQLite 事务并记录 SQL/timeout 参数，仅模拟 MySQL 的事务控制语法。 */
class TransactionScopeSqliteClient extends Connection
{
    public array $queries = [];
    public array $failCommands = [];
    public bool $closed = false;
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array { return $this->executeSql($builder->getLastPrepareQuery(), $builder->getLastBindParams(), $timeout); }
    public function rawQuery(string $query, ?float $timeout = null): bool|array { return $this->executeSql($query, [], $timeout); }
    public function beforeUse(): bool { return true; }
    public function intervalCheck(): bool { return true; }
    public function close(): bool
    {
        // 关闭真实 MySQL 会话会回滚其事务；这里用 SQLite 模拟这一效果。
        if ($this->database->inTransaction()) { $this->database->rollBack(); }
        $this->closed = true;
        return parent::close();
    }
    private function executeSql(string $query, array $parameters, ?float $timeout): bool|array
    {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) { throw new \InvalidArgumentException('Timeout must be finite and positive'); }
        $this->queries[] = ['sql' => $query, 'parameters' => $parameters, 'timeout' => $timeout];
        $this->lastAffectRows = $this->lastInsertId = 0;
        if (preg_match('/\A(START|COMMIT|ROLLBACK)\b/', $query, $match) && array_key_exists($match[1], $this->failCommands)) {
            $failure = $this->failCommands[$match[1]];
            if ($failure instanceof \Throwable) { throw $failure; }
            return $failure;
        }
        if (str_starts_with($query, 'START TRANSACTION')) { $this->database->beginTransaction(); $this->isInTransaction = true; return true; }
        if (str_starts_with($query, 'COMMIT')) { $this->database->commit(); $this->isInTransaction = false; return true; }
        if (str_starts_with($query, 'ROLLBACK')) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            $this->isInTransaction = false; return true;
        }
        $statement = $this->database->prepare($query);
        $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount();
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
}

/** 使用真实 AbstractPool 分配和归还流程，仅替换网络客户端的创建方法。 */
class TransactionScopeLifecyclePool extends Pool
{
    public int $borrows = 0;
    public int $recycles = 0;
    public int $resets = 0;
    public array $recycleStates = [];
    public function __construct(FastDbConfig $config, private TransactionScopeSqliteClient $client) { parent::__construct($config); }
    protected function createObject(): ObjectInterface { return $this->client; }
    public function getObj(?float $timeout = null, int $tryTimes = 3): ?ObjectInterface
    {
        $this->borrows++;
        return parent::getObj($timeout, $tryTimes);
    }
    public function recycleObj($obj): bool
    {
        $this->recycles++;
        $this->recycleStates[] = ['closed' => $obj->closed, 'active' => $obj->database->inTransaction(), 'scope' => $obj->__getTransactionScope()];
        return parent::recycleObj($obj);
    }
    public function reset(): AbstractPool { $this->resets++; return parent::reset(); }
}

/** 模拟物理关闭失败时，保留原上下文和 SQLite 事务。 */
class TransactionScopeCleanupFailureClient extends TransactionScopeSqliteClient
{
    public bool $allowClose = false;
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        if (!$this->allowClose && str_starts_with($query, 'ROLLBACK')) {
            $this->queries[] = ['sql' => $query, 'parameters' => [], 'timeout' => $timeout];
            return false;
        }
        return parent::rawQuery($query, $timeout);
    }
    public function close(): bool { return $this->allowClose ? parent::close() : false; }
}
