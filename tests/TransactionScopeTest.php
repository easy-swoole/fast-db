<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config as FastDbConfig;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class TransactionScopeTest extends TestCase
{
    private FastDb $db;
    /** @var array<string, TransactionScopeSqliteClient> */
    private array $connections;

    protected function setUp(): void
    {
        $this->db = (new FastDb())->addDb(new FastDbConfig(), 'a')->addDb(new FastDbConfig(), 'b');
        foreach (['a', 'b'] as $name) {
            $database = new \PDO('sqlite::memory:');
            $database->exec('CREATE TABLE ledger (value INTEGER NOT NULL)');
            $this->connections[$name] = new TransactionScopeSqliteClient($database);
            $this->connections[$name]->connectionName = $name;
        }
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $this->connections]);
        $this->db->selectConnection('a');
    }

    protected function tearDown(): void
    {
        $this->db->setOnQuery(static function (): void {});
        foreach ($this->connections as $connection) { $connection->close(); }
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
    }

    private function insert(int $value): void { $this->db->rawQuery('INSERT INTO ledger (value) VALUES (' . $value . ')'); }
    private function values(string $name = 'a'): array
    {
        return array_map('intval', $this->connections[$name]->database->query('SELECT value FROM ledger ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN));
    }
    private function commands(string $name = 'a'): array
    {
        return array_values(array_filter(array_map(static function (array $query): ?string {
            return preg_match('/\A(START|COMMIT|ROLLBACK)\b/', $query['sql'], $match) ? $match[1] : null;
        }, $this->connections[$name]->queries)));
    }

    public static function returnedValues(): array { return [[false], [null], [42], ['done'], [['id' => 1]]]; }

    #[DataProvider('returnedValues')]
    public function testOwnerCommitsEveryNormalReturnValue(mixed $value): void
    {
        $result = $this->db->transaction(function (FastDb $db) use ($value): mixed {
            $this->assertSame($this->db, $db);
            $this->insert(1);
            return $value;
        });
        $this->assertSame($value, $result);
        $this->assertSame([1], $this->values());
        $this->assertSame(['START', 'COMMIT'], $this->commands());
        $this->assertFalse($this->connections['a']->isInTransaction());
    }

    public function testEscapingBusinessExceptionRollsBackAndKeepsTheSameThrowable(): void
    {
        $failure = new \Error('business failure');
        try {
            $this->db->transaction(function () use ($failure): void { $this->insert(1); throw $failure; });
            $this->fail('Expected business error');
        } catch (\Error $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->values());
        $this->assertSame(['START', 'ROLLBACK'], $this->commands());
    }

    public function testCaughtJoinedFailureDoesNotPreventTheOwnerFromCommitting(): void
    {
        $failure = new \RuntimeException('inner failure');
        $this->db->transaction(function () use ($failure): void {
            $this->insert(1);
            try { $this->db->transaction(function () use ($failure): void { $this->insert(2); throw $failure; }); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
            $this->insert(3);
        });
        $this->assertSame([1, 2, 3], $this->values());
        $this->assertSame(['START', 'COMMIT'], $this->commands());
    }

    public function testUncaughtJoinedFailureReachesTheOwnerAndRollsEverythingBack(): void
    {
        $failure = new \RuntimeException('inner failure');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->insert(1);
                $this->db->transaction(function () use ($failure): void { $this->insert(2); throw $failure; });
            });
            $this->fail('Expected inner error');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->values());
        $this->assertSame(['START', 'ROLLBACK'], $this->commands());
    }

    public function testJoiningManualTransactionIgnoresStartOptionsAndLeavesItsBoundaryToTheCaller(): void
    {
        $this->db->begin(flags: TransactionStartFlags::ReadWrite);
        $this->assertFalse($this->db->transaction(function (): bool { $this->insert(1); return false; },
            TransactionStartFlags::ReadOnly, 0.125));
        $this->assertTrue($this->connections['a']->database->inTransaction());
        $this->assertSame(['START'], $this->commands());
        $this->assertTrue($this->db->commit());
        $this->assertSame([1], $this->values());
    }

    public function testFailureWhileJoiningManualTransactionDoesNotEndOrMarkIt(): void
    {
        $failure = new \RuntimeException('joined failure');
        $this->db->begin();
        $this->insert(1);
        try { $this->db->transaction(function () use ($failure): void { $this->insert(2); throw $failure; }); $this->fail('Expected joined failure'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertTrue($this->connections['a']->isInTransaction());
        $this->assertSame(['START'], $this->commands());
        $this->assertTrue($this->db->commit());
        $this->assertSame([1, 2], $this->values());
    }

    public function testAtoBtoAUsesOneTransactionPerActualConnectionAndCaughtInnerFailureCanCommit(): void
    {
        $failure = new \RuntimeException('joined A failure');
        $this->db->transaction(function () use ($failure): void {
            $this->insert(1);
            $this->db->withConnection('b', function () use ($failure): void {
                $this->db->transaction(function () use ($failure): void {
                    $this->insert(4);
                    try {
                        $this->db->withConnection('a', function () use ($failure): void {
                            $this->db->transaction(function () use ($failure): void { $this->insert(2); throw $failure; });
                        });
                    } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
                });
            });
            $this->insert(3);
        });
        $this->assertSame([1, 2, 3], $this->values('a'));
        $this->assertSame([4], $this->values('b'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('b'));
        $this->assertSame('a', $this->db->selectConnection());
    }

    public function testCommittedBIsIndependentFromALaterRollbackOfA(): void
    {
        $failure = new \RuntimeException('A failure');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->insert(1);
                $this->db->withConnection('b', function (): void { $this->db->transaction(function (): void { $this->insert(2); }); });
                throw $failure;
            });
            $this->fail('Expected A failure');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame([], $this->values('a'));
        $this->assertSame([2], $this->values('b'));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('b'));
    }

    public function testChangingSelectionDoesNotChangeWhichConnectionTheOwnerCommits(): void
    {
        $this->db->transaction(function (): void {
            $this->insert(1);
            $this->db->selectConnection('b');
            $this->insert(2);
        });
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
        $this->assertSame('b', $this->db->selectConnection());
    }

    public static function boundaryFailures(): array { return [['false'], ['throw']]; }

    #[DataProvider('boundaryFailures')]
    public function testBeginFailureNeverRunsTheBusinessCallback(string $mode): void
    {
        $failure = new \RuntimeException('begin failed');
        $this->connections['a']->failCommands['START'] = $mode === 'throw' ? $failure : false;
        $entered = false;
        try {
            $this->db->transaction(static function () use (&$entered): void { $entered = true; });
            $this->fail('Expected begin failure');
        } catch (\Throwable $error) {
            if ($mode === 'throw') { $this->assertSame($failure, $error); }
            else { $this->assertInstanceOf(RuntimeError::class, $error); }
        }
        $this->assertFalse($entered);
        $this->assertSame(['START'], $this->commands());
    }

    #[DataProvider('boundaryFailures')]
    public function testCommitFailureIsReportedWithoutAutomaticRollbackOrRetry(string $mode): void
    {
        $failure = new \RuntimeException('commit failed');
        $this->connections['a']->failCommands['COMMIT'] = $mode === 'throw' ? $failure : false;
        try { $this->db->transaction(function (): void { $this->insert(1); }); $this->fail('Expected commit failure'); }
        catch (\Throwable $error) {
            if ($mode === 'throw') { $this->assertSame($failure, $error); }
            else { $this->assertInstanceOf(RuntimeError::class, $error); }
        }
        $this->assertSame(['START', 'COMMIT'], $this->commands());
        $this->assertTrue($this->connections['a']->database->inTransaction());
    }

    public function testRollbackFailureDoesNotReplaceTheBusinessException(): void
    {
        $business = new \RuntimeException('business failed');
        $this->connections['a']->failCommands['ROLLBACK'] = new \RuntimeException('cleanup failed');
        try { $this->db->transaction(function () use ($business): void { $this->insert(1); throw $business; }); $this->fail('Expected business failure'); }
        catch (\RuntimeException $error) { $this->assertSame($business, $error); }
        $this->assertSame(['START', 'ROLLBACK'], $this->commands());
    }

    public function testOwnerPassesControlOptionsWithoutTurningTimeoutIntoABusinessDeadline(): void
    {
        $this->db->transaction(function (): void { $this->db->rawQuery('INSERT INTO ledger (value) VALUES (1)', 0.25); },
            TransactionStartFlags::ReadOnly, 0.125);
        $queries = $this->connections['a']->queries;
        $this->assertSame('START TRANSACTION READ ONLY', $queries[0]['sql']);
        $this->assertSame([0.125, 0.25, 0.125], array_column($queries, 'timeout'));
    }

    public static function invalidTimeouts(): array { return [[0.0], [-1.0], [INF], [NAN]]; }

    #[DataProvider('invalidTimeouts')]
    public function testInvalidTimeoutIsRejectedBeforeBeginning(float $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->transaction(static function (): void { throw new \LogicException('Callback must not run'); }, timeout: $timeout);
    }
}

/** 使用真实 SQLite 事务记录实际写入，仅替换网络访问及 MySQL 控制语法。 */
class TransactionScopeSqliteClient extends Connection
{
    public bool $isInTransaction = false;
    public array $queries = [];
    public array $failCommands = [];
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function isInTransaction(): bool { return $this->isInTransaction; }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        return $this->executeSql($builder->getLastPrepareQuery(), $builder->getLastBindParams(), $timeout);
    }
    public function rawQuery(string $query, ?float $timeout = null): bool|array { return $this->executeSql($query, [], $timeout); }
    public function beginTransaction(TransactionStartFlags $flags, ?float $timeout = null): bool { return $this->rawQuery($flags->toSql(), $timeout) === true; }
    public function commitTransaction(TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool { return $this->rawQuery('COMMIT' . $flags->toSqlSuffix(), $timeout) === true; }
    public function rollbackTransaction(TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool { return $this->rawQuery('ROLLBACK' . $flags->toSqlSuffix(), $timeout) === true; }
    public function close(): bool
    {
        if ($this->database->inTransaction()) { $this->database->rollBack(); }
        $closed = parent::close();
        if ($closed) { $this->isInTransaction = false; }
        return $closed;
    }
    private function executeSql(string $query, array $parameters, ?float $timeout): bool|array
    {
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
