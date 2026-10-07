<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    private FastDb $db;
    private \mysqli $observer;
    private string $table;
    private \ReflectionProperty $instance;
    private mixed $original;

    protected function setUp(): void
    {
        $this->instance = new \ReflectionProperty(FastDb::class, 'instance');
        $this->original = $this->instance->getValue();
        $this->db = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
        $this->instance->setValue(null, $this->db);
        $this->observer = new \mysqli(MYSQL_CONFIG['host'], MYSQL_CONFIG['user'], MYSQL_CONFIG['password'],
            MYSQL_CONFIG['database'], MYSQL_CONFIG['port']);
        $this->table = 'fastdb_tx_' . bin2hex(random_bytes(8));
        TransactionEntity::$table = $this->table;
        $this->observer->query("CREATE TABLE `{$this->table}` (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (1, 0)");
    }

    protected function tearDown(): void
    {
        try {
            $this->db->setOnQuery(static function (): void {});
            $this->db->recycleContext();
            $this->db->reset();
            $this->observer->query("DROP TABLE IF EXISTS `{$this->table}`");
            $this->observer->close();
        } finally {
            $this->instance->setValue(null, $this->original);
        }
    }

    private function write(int $value): void
    {
        $this->db->rawQuery("UPDATE `{$this->table}` SET value = {$value} WHERE id = 1");
    }

    private function value(): int
    {
        return (int) $this->observer->query("SELECT value FROM `{$this->table}` WHERE id = 1")->fetch_assoc()['value'];
    }

    public static function transactionOperations(): array
    {
        return [
            'begin callback' => ['begin', 'start transaction', true, 0],
            'commit callback' => ['commit', 'commit', false, 1],
            'rollback callback' => ['rollback', 'rollback', false, 0],
        ];
    }

    #[DataProvider('transactionOperations')]
    public function testCallbackExceptionKeepsActualTransactionState(string $operation, string $sql, bool $active, int $value): void
    {
        if($operation !== 'begin'){
            $this->db->begin();
            $this->write(1);
        }
        $this->db->setOnQuery(static function (QueryResult $result) use ($sql): void {
            if($result->getRawSql() === $sql){
                throw new \RuntimeException('transaction logger error');
            }
        });
        try {
            $this->db->{$operation}();
            $this->fail('Expected the logger exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('transaction logger error', $error->getMessage());
        }
        $this->assertSame($active, $this->db->isInTransaction());
        $this->assertSame($value, $this->value());
        $this->db->setOnQuery(static function (): void {});
        if($active){
            $this->write(2);
            $this->assertTrue($this->db->rollback());
        }
        $this->assertTrue($this->db->begin());
        $this->write(3);
        $this->assertTrue($this->db->rollback());
        $this->assertSame($value, $this->value());
    }

    public function testInvokePreservesExistingTransaction(): void
    {
        $this->db->begin();
        $connection = $this->db->currentConnection();
        $this->write(1);
        $this->assertSame('result', $this->db->invoke(static fn () => 'result'));
        $this->assertSame($connection, $this->db->currentConnection());
        $this->assertTrue($this->db->isInTransaction());
        $this->assertTrue($this->db->commit());
        $this->assertSame(1, $this->value());
    }

    public function testNestedInvokeBusinessExceptionRollsBackAllWrites(): void
    {
        try {
            $this->db->invoke(function ($outer): void {
                $this->db->begin();
                $this->write(1);
                $this->db->invoke(function ($inner) use ($outer): void {
                    $this->assertSame($outer, $inner);
                });
                $this->write(2);
                throw new \RuntimeException('outer error');
            });
            $this->fail('Expected business exception');
        } catch (\RuntimeException $error) {
            $this->assertSame('outer error', $error->getMessage());
        }
        $this->assertNull($this->db->currentConnection());
        $this->assertSame(0, $this->value());
    }

    public function testNestedInvokeCanCommitOuterTransaction(): void
    {
        $this->db->invoke(function (): void {
            $this->db->begin();
            $this->db->invoke(function (): void { $this->write(1); });
            $this->write(2);
            $this->assertTrue($this->db->commit());
        });
        $this->assertNull($this->db->currentConnection());
        $this->assertSame(2, $this->value());
    }

    public function testIndependentManagersDoNotCommitOrRollbackEachOther(): void
    {
        $other = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
        try {
            $this->db->begin();
            $this->write(1);
            $other->begin();
            $this->assertTrue($other->isInTransaction());
            $this->assertTrue($other->rollback());
            $this->assertFalse($other->isInTransaction());
            $this->assertTrue($this->db->isInTransaction());
            $this->assertSame(0, $this->value());
            $this->assertTrue($this->db->rollback());
            $other->begin();
            $other->rawQuery("UPDATE `{$this->table}` SET value = 2 WHERE id = 1");
            $this->assertTrue($other->commit());
            $this->assertFalse($other->isInTransaction());
            $this->assertSame(2, $this->value());
        } finally {
            $other->recycleContext();
            $other->reset();
        }
    }

    public static function rollbackModes(): array
    {
        return ['explicit' => ['rollback'], 'recycle' => ['recycleContext'], 'invoke exception' => ['invoke']];
    }

    #[DataProvider('rollbackModes')]
    public function testRollbackRestoresFirstEntityBaselineForRetry(string $mode): void
    {
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $updates = function () use ($entity): void {
            $this->db->begin();
            $entity->value = 1;
            $this->assertTrue($entity->update());
            $entity->value = 2;
            $this->assertTrue($entity->update());
        };
        if($mode === 'invoke'){
            $this->db->recycleContext();
            try {
                $this->db->invoke(static function () use ($updates): void {
                    $updates();
                    throw new \RuntimeException('business error');
                });
            } catch (\RuntimeException $error) {
                $this->assertSame('business error', $error->getMessage());
            }
        } else {
            $updates();
            $this->db->{$mode}();
        }
        $this->assertSame(0, $this->value());
        $this->assertSame(2, $entity->value);
        $this->assertTrue($entity->update());
        $this->assertSame(2, $this->value());
    }

    public function testCommitClearsSnapshotsBeforeNextRollback(): void
    {
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $this->db->begin();
        $entity->value = 1;
        $this->assertTrue($entity->update());
        $this->db->commit();
        $this->db->begin();
        $entity->value = 2;
        $this->assertTrue($entity->update());
        $this->db->rollback();
        $entity->value = 1;
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
        $this->assertSame(1, $this->value());
    }

    public function testSqlExceptionCanBeRolledBack(): void
    {
        $this->db->begin();
        $this->write(1);
        try {
            $this->db->rawQuery('SELECT fastdb_missing_tx_column');
            $this->fail('Expected SQL error');
        } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            $this->assertStringContainsString('fastdb_missing_tx_column', $error->getMessage());
        }
        $this->assertTrue($this->db->rollback());
        $this->assertFalse($this->db->isInTransaction());
        $this->assertSame(0, $this->value());
    }
}

final class TransactionEntity extends AbstractEntity
{
    public static string $table;
    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public int $value;

    public function tableName(): string
    {
        return self::$table;
    }
}
