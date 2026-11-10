<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/** 每个真实数据库用例只使用一张名称唯一的 InnoDB 表，不使用共享测试表。 */
final class ScopeIntegrationTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private ?FastDb $db = null;
    private ?\mysqli $observer = null;
    private ?string $table = null;
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') || !defined('MYSQL_CONFIG')) { $this->markTestSkipped('Native mysqli and the configured test database are required.'); }
        if (Coroutine::getCid() < 0) { $this->markTestSkipped('Run integration tests through tests/run.php in a Swoole coroutine.'); }
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->db = (new FastDb())->addDb(new Config(MYSQL_CONFIG), 'a')->addDb(new Config(MYSQL_CONFIG), 'b');
        $this->singleton->setValue(null, $this->db);
        try {
            $this->observer = new \mysqli(MYSQL_CONFIG['host'], MYSQL_CONFIG['user'], MYSQL_CONFIG['password'],
                MYSQL_CONFIG['database'], MYSQL_CONFIG['port']);
            if ($this->observer->connect_errno) { throw new \RuntimeException('Cannot connect the independent integration observer'); }
            $this->observer->set_charset(MYSQL_CONFIG['charset'] ?? 'utf8mb4');
            $this->table = 'fastdb_scope_' . bin2hex(random_bytes(10));
            $this->observer->query("CREATE TABLE `{$this->table}` (`id` INT PRIMARY KEY, `value` INT NOT NULL) ENGINE=InnoDB");
            $this->observer->query("INSERT INTO `{$this->table}` VALUES (1,0),(2,0)");
            $this->db->selectConnection('a');
            $this->db->setOnQuery(function (QueryResult $result): void { $this->events[] = $result; });
        } catch (\Throwable $error) {
            try { if ($this->observer !== null && $this->table !== null) { $this->observer->query("DROP TABLE IF EXISTS `{$this->table}`"); } }
            finally {
                if ($this->observer !== null) { $this->observer->close(); $this->observer = null; }
                $this->singleton->setValue(null, $this->originalInstance);
            }
            throw $error;
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db !== null) {
                $this->db->setOnQuery(static function (): void {});
                if ($this->observer !== null) { $this->observer->rollback(); }
                try { $this->db->recycleContext(false); }
                finally { $this->db->reset(); }
            }
        } finally {
            try {
                if ($this->observer !== null) {
                    if ($this->table !== null) { $this->observer->query("DROP TABLE IF EXISTS `{$this->table}`"); }
                    $this->observer->close();
                }
            } finally {
                if ($this->singleton !== null) { $this->singleton->setValue(null, $this->originalInstance); }
            }
        }
    }

    private function write(int $value, int $id = 1): void
    {
        $this->db->rawQuery("UPDATE `{$this->table}` SET `value`={$value} WHERE `id`={$id}");
    }

    private function preparedWrite(int $value, int $id = 1): void
    {
        $this->db->query(function (QueryBuilder $query) use ($value, $id): void {
            $query->where('id', $id)->update($this->table, ['value' => $value]);
        });
    }

    private function value(int $id = 1): int
    {
        $result = $this->observer->query("SELECT `value` FROM `{$this->table}` WHERE `id`={$id}");
        $this->assertInstanceOf(\mysqli_result::class, $result);
        return (int)$result->fetch_assoc()['value'];
    }

    private function commands(string $name): array
    {
        $commands = [];
        foreach ($this->events as $event) {
            if ($event->getConnectionName() === $name && preg_match('/\A(START|COMMIT|ROLLBACK)\b/', $event->getRawSql() ?? '', $match)) {
                $commands[] = $match[1];
            }
        }
        return $commands;
    }

    private function preparedCount(): int
    {
        $row = $this->db->rawQuery("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'")->getResultOne();
        return (int)($row['Value'] ?? $row['value']);
    }

    public function testAtoBtoAUsesIndependentClientsAndDoesNotCommitTheInnerA(): void
    {
        $a = (string)$this->db->rawQuery('SELECT CONNECTION_ID() AS id')->getResultOne()['id'];
        $b = $this->db->withConnection('b', fn(): string => (string)$this->db->rawQuery('SELECT CONNECTION_ID() AS id')->getResultOne()['id']);
        $this->assertNotSame($a, $b);
        $this->assertSame('outer', $this->db->transaction(function (): string {
            $this->write(1);
            $this->db->withConnection('b', function (): void {
                $this->assertSame(0, (int)$this->db->rawQuery("SELECT `value` FROM `{$this->table}` WHERE `id`=1")->getResultOne()['value']);
                $this->db->withConnection('a', function (): void {
                    $this->assertSame('inner', $this->db->transaction(function (): string { $this->preparedWrite(2); return 'inner'; }));
                    $this->assertSame(0, $this->value());
                    $this->assertSame(['START'], $this->commands('a'));
                });
                $this->assertSame('b', $this->db->selectConnection());
                $this->assertSame(0, (int)$this->db->rawQuery("SELECT `value` FROM `{$this->table}` WHERE `id`=1")->getResultOne()['value']);
            });
            $this->assertSame('a', $this->db->selectConnection());
            return 'outer';
        }));
        $this->assertSame(2, $this->value());
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
    }

    public function testCaughtInnerAFailureRollsBackEveryAWriteAndThrowsRollbackOnly(): void
    {
        $failure = new \RuntimeException('inner business failure');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->write(1);
                try { $this->db->transaction(function () use ($failure): void { $this->preparedWrite(2); throw $failure; }); }
                catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
                $this->write(3, 2);
            });
            $this->fail('A caught joined failure must prevent the outer commit');
        } catch (RuntimeError $error) { $this->assertStringContainsString('rollback', strtolower($error->getMessage())); }
        $this->assertSame(0, $this->value(1));
        $this->assertSame(0, $this->value(2));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertFalse($this->db->isInTransaction());
    }

    public function testIndependentBCommitSurvivesTheLaterRollbackOfA(): void
    {
        $failure = new \RuntimeException('outer a failure');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->write(1, 1);
                $this->db->withConnection('b', function (): void {
                    $this->db->transaction(function (): void { $this->preparedWrite(20, 2); });
                });
                $this->assertSame(0, $this->value(1));
                $this->assertSame(20, $this->value(2));
                throw $failure;
            });
            $this->fail('The original A failure must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(0, $this->value(1));
        $this->assertSame(20, $this->value(2));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertSame(['START', 'COMMIT'], $this->commands('b'));
    }

    public function testJoinedManualTransactionRefusesCommitAndWaitsForExplicitRollback(): void
    {
        $this->assertTrue($this->db->begin());
        $failure = new \RuntimeException('joined failure');
        try { $this->db->transaction(function () use ($failure): void { $this->preparedWrite(9); throw $failure; }); $this->fail('Joined body must fail'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        try { $this->db->commit(); $this->fail('Rollback-only manual commit must be rejected'); }
        catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertTrue($this->db->isInTransaction());
        $this->assertTrue($this->db->currentConnection()->mysqlClient()->inTransaction());
        $this->assertSame(0, $this->value());
        $this->assertSame(['START'], $this->commands('a'));
        $this->assertTrue($this->db->rollback());
        $this->assertSame(0, $this->value());
        $this->assertFalse($this->db->isInTransaction());
    }

    public function testCommitObserverCanOpenANewTransactionWithoutTheOldOwnerRollingItBack(): void
    {
        $failure = new \RuntimeException('commit observer failed after opening another transaction');
        $entered = false;
        $newToken = null;
        $this->db->setOnQuery(function (QueryResult $result) use ($failure, &$entered, &$newToken): void {
            $this->events[] = $result;
            if ($entered || !str_starts_with($result->getRawSql() ?? '', 'COMMIT')) { return; }
            $entered = true;
            $this->assertTrue($this->db->begin());
            $this->preparedWrite(20, 2);
            $newToken = $this->db->getTransactionContext()->token();
            throw $failure;
        });
        try { $this->db->transaction(function (): void { $this->write(10, 1); }); $this->fail('Commit observer failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertTrue($entered);
        $this->assertSame(10, $this->value(1));
        $this->assertSame(0, $this->value(2));
        $this->assertTrue($this->db->isInTransaction());
        $this->assertSame($newToken, $this->db->getTransactionContext()->token());
        $this->assertFalse($newToken->wasRolledBack());
        $this->assertSame(['START', 'COMMIT', 'START'], $this->commands('a'));
        $this->db->setOnQuery(static function (): void {});
        $this->assertTrue($this->db->rollback());
        $this->assertSame(10, $this->value(1));
        $this->assertSame(0, $this->value(2));
    }

    public static function guardedStatements(): array
    {
        return [
            ['facade-raw', 'COMMIT'],
            ['facade-prepared', 'COMMIT'],
            ['client-raw', 'COMMIT'],
            ['client-prepared', 'COMMIT'],
            ['facade-raw', 'ROLLBACK'],
            ['facade-prepared', 'START TRANSACTION'],
            ['facade-raw', 'SET autocommit=1'],
            ['facade-raw', 'ALTER'],
            ['facade-prepared', 'ALTER'],
            ['client-prepared', 'ALTER'],
        ];
    }

    #[DataProvider('guardedStatements')]
    public function testRawAndPreparedControlOrDdlAreRejectedBeforeWireAndCannotImplicitlyCommit(string $route, string $operation): void
    {
        $sql = $operation === 'ALTER' ? "ALTER TABLE `{$this->table}` ADD COLUMN scope_escaped INT" : $operation;
        try {
            $this->db->transaction(function () use ($route, $sql): void {
                $this->write(1);
                $before = $this->preparedCount();
                try {
                    if ($route === 'facade-raw') { $this->db->rawQuery($sql); }
                    elseif ($route === 'client-raw') { $this->db->currentConnection()->rawQuery($sql); }
                    else {
                        $query = (new QueryBuilder())->raw($sql);
                        if ($route === 'facade-prepared') { $this->db->query($query); }
                        else { $this->db->currentConnection()->query($query); }
                    }
                    $this->fail('Transaction-boundary SQL must be rejected before it reaches the server');
                } catch (RuntimeError $error) {
                    $this->assertSame($before, $this->preparedCount(), 'Rejected prepared SQL must not allocate a server statement.');
                    $this->assertSame(0, $this->value());
                    $this->assertTrue($this->db->currentConnection()->mysqlClient()->inTransaction());
                    throw $error;
                }
            });
            $this->fail('The rejected control statement must propagate');
        } catch (RuntimeError) { $this->addToAssertionCount(1); }
        $this->assertSame(0, $this->value());
        $this->assertSame(2, (int)$this->observer->query("SELECT COUNT(*) AS n FROM `{$this->table}`")->fetch_assoc()['n']);
        $this->assertSame(0, $this->observer->query("SHOW COLUMNS FROM `{$this->table}` LIKE 'scope_escaped'")->num_rows);
    }

    public function testReadOnlyFlagsAreExecutedByTheServerAndStillAllowReads(): void
    {
        $this->assertSame(0, $this->db->transaction(fn(): int => (int)$this->db->rawQuery("SELECT `value` FROM `{$this->table}` WHERE `id`=1")->getResultOne()['value'], TransactionStartFlags::ReadOnly));
        $this->assertSame('START TRANSACTION READ ONLY', $this->events[0]->getRawSql());
        $this->assertSame(['START', 'COMMIT'], $this->commands('a'));
        $this->assertSame(0, $this->value());
    }

    public function testBulkInsertAndUpsertFactoriesRemainInsideTheOwnedTransactionAndRollBackEveryBatch(): void
    {
        $failure = new \RuntimeException('rollback all completed bulk batches');
        $batches = [];
        $onQuery = static function (QueryResult $result) use (&$batches): void { $batches[] = $result; };
        try {
            $this->db->transaction(function () use ($failure, $onQuery, &$batches): void {
                $this->assertSame(3, ScopeIntegrationBulkRecord::fastInsertAll([
                    ['id' => 3, 'value' => 3], ['id' => 4, 'value' => 4], ['id' => 5, 'value' => 5],
                ], $this->table, $onQuery, 2));
                $this->assertSame(3, ScopeIntegrationBulkRecord::fastUpsert([
                    ['id' => 3, 'value' => 30], ['id' => 6, 'value' => 60],
                ], ['value'], $this->table, $onQuery, 1));
                $this->assertCount(4, $batches);
                foreach ($batches as $batch) {
                    $this->assertSame('a', $batch->getConnectionName());
                    $this->assertNull($batch->getException());
                }
                $rows = $this->db->rawQuery("SELECT `id`,`value` FROM `{$this->table}` WHERE `id`>=3 ORDER BY `id`")->getResult();
                $this->assertSame([3, 4, 5, 6], array_map('intval', array_column($rows, 'id')));
                $this->assertSame([30, 4, 5, 60], array_map('intval', array_column($rows, 'value')));
                $this->db->withConnection('b', function (): void {
                    $this->assertSame(0, (int)$this->db->rawQuery("SELECT COUNT(*) AS n FROM `{$this->table}` WHERE `id`>=3")->getResultOne()['n']);
                });
                throw $failure;
            });
            $this->fail('The outer business failure must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(2, (int)$this->observer->query("SELECT COUNT(*) AS n FROM `{$this->table}`")->fetch_assoc()['n']);
        $this->assertSame(0, $this->value(1));
        $this->assertSame(0, $this->value(2));
        $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        $this->assertSame([], $this->commands('b'));
    }

    public function testNoBackslashEscapesCannotHideAutocommitBehindAnAmbiguousQuotedSetValue(): void
    {
        $saved = (string)$this->db->rawQuery('SELECT @@session.sql_mode AS mode')->getResultOne()['mode'];
        $modes = $saved === '' ? [] : explode(',', $saved);
        if (!in_array('NO_BACKSLASH_ESCAPES', $modes, true)) { $modes[] = 'NO_BACKSLASH_ESCAPES'; }
        try {
            $this->db->query((new QueryBuilder())->raw('SET SESSION sql_mode = ?', [implode(',', $modes)]));
            $actual = (string)$this->db->rawQuery('SELECT @@session.sql_mode AS mode')->getResultOne()['mode'];
            $this->assertContains('NO_BACKSLASH_ESCAPES', explode(',', $actual));
            $sql = "SET @scope_saved='" . chr(92) . "' , autocommit=1 # ignored quote'";
            try {
                $this->db->transaction(function () use ($sql): void {
                    $this->write(1);
                    try { $this->db->rawQuery($sql); $this->fail('The SQL mode must not conceal an implicit transaction boundary'); }
                    catch (RuntimeError $error) {
                        $this->assertTrue($this->db->currentConnection()->mysqlClient()->inTransaction());
                        $this->assertSame(0, $this->value());
                        throw $error;
                    }
                });
                $this->fail('The guard error must escape the owned transaction');
            } catch (RuntimeError) { $this->addToAssertionCount(1); }
            $this->assertSame(0, $this->value());
            $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        } finally {
            // 即使断言失败，也要在托管作用域外恢复当前借用会话的设置。
            $this->db->query((new QueryBuilder())->raw('SET SESSION sql_mode = ?', [$saved]));
        }
    }

    public static function ignoredExecutionCommentAutocommitStatements(): array
    {
        return [
            'future MySQL version' => ['SET @x=1 /*!99999 ( */, autocommit=1 /*!99999 ) */'],
            'MariaDB execution comments' => ['SET @x=1 /*M!99999 ( */, autocommit=1 /*M!99999 ) */'],
            'future MySQL prefix before COMMIT' => ['/*!99999 SELECT */ COMMIT'],
            'MariaDB prefix before COMMIT' => ['/*M!99999 SELECT */ COMMIT'],
        ];
    }

    #[DataProvider('ignoredExecutionCommentAutocommitStatements')]
    public function testIgnoredExecutionCommentsCannotConcealAnAutocommitAssignment(string $sql): void
    {
        $saved = (int)$this->db->rawQuery('SELECT @@session.autocommit AS autocommit')->getResultOne()['autocommit'];
        try {
            $this->db->query((new QueryBuilder())->raw('SET SESSION autocommit = ?', [0]));
            $this->assertSame(0, (int)$this->db->rawQuery('SELECT @@session.autocommit AS autocommit')->getResultOne()['autocommit']);
            try {
                $this->db->transaction(function () use ($sql): void {
                    $this->write(1);
                    try { $this->db->rawQuery($sql); $this->fail('Ignored execution comments must not hide an implicit commit'); }
                    catch (RuntimeError $error) {
                        $this->assertTrue($this->db->currentConnection()->mysqlClient()->inTransaction());
                        $this->assertSame(0, $this->value());
                        $this->assertSame(0, (int)$this->db->rawQuery('SELECT @@session.autocommit AS autocommit')->getResultOne()['autocommit']);
                        throw $error;
                    }
                });
                $this->fail('The guarded SET must fail before reaching the server');
            } catch (RuntimeError) { $this->addToAssertionCount(1); }
            $this->assertSame(0, $this->value());
            $this->assertSame(['START', 'ROLLBACK'], $this->commands('a'));
        } finally {
            // 先回滚仍由本用例持有的事务，再恢复会话设置。
            if ($this->db->isInTransaction()) { $this->assertTrue($this->db->rollback()); }
            $this->db->query((new QueryBuilder())->raw('SET SESSION autocommit = ?', [$saved]));
        }
    }

    public function testAClientLockTimeoutCannotReconnectAndContinueWritingInsideTheOldScope(): void
    {
        $this->observer->begin_transaction();
        $this->observer->query("UPDATE `{$this->table}` SET `value`=99 WHERE `id`=1");
        $timeout = null;
        try {
            try {
                $this->db->transaction(function () use (&$timeout): void {
                    try { $this->db->rawQuery("UPDATE `{$this->table}` SET `value`=1 WHERE `id`=1", 0.03); }
                    catch (TimeoutException $error) { $timeout = $error; }
                    $this->assertInstanceOf(TimeoutException::class, $timeout);
                    try { $this->write(20, 2); $this->fail('The expired transaction must not continue on an implicitly reconnected session'); }
                    catch (\Throwable $blocked) {
                        if ($blocked instanceof \PHPUnit\Framework\AssertionFailedError) { throw $blocked; }
                        $this->addToAssertionCount(1);
                    }
                    throw $timeout;
                });
                $this->fail('The lock timeout must propagate');
            } catch (TimeoutException $error) { $this->assertSame($timeout, $error); }
        } finally { $this->observer->rollback(); }
        $this->assertSame(0, $this->value(1));
        $this->assertSame(0, $this->value(2));
    }
}

class ScopeIntegrationBulkRecord extends AbstractEntity
{
    public function tableName(): string { throw new \LogicException('Bulk calls provide their isolated table explicitly.'); }
}
