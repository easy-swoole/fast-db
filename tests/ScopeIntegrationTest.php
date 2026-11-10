<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/** 每个用例创建随机命名的独立表，不读取或修改业务表。 */
final class ScopeIntegrationTest extends TestCase
{
    private ?FastDb $db = null;
    private ?\mysqli $observer = null;
    private ?string $table = null;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli') || !defined('MYSQL_CONFIG')) { $this->markTestSkipped('Native mysqli and test database configuration are required.'); }
        if (Coroutine::getCid() < 0) { $this->markTestSkipped('Run through tests/run.php in a Swoole coroutine.'); }
        $this->db = (new FastDb())->addDb(new Config(MYSQL_CONFIG), 'a')->addDb(new Config(MYSQL_CONFIG), 'b');
        $this->observer = new \mysqli(MYSQL_CONFIG['host'], MYSQL_CONFIG['user'], MYSQL_CONFIG['password'],
            MYSQL_CONFIG['database'], MYSQL_CONFIG['port']);
        $this->observer->set_charset(MYSQL_CONFIG['charset'] ?? 'utf8mb4');
        $this->table = 'fastdb_scope_' . bin2hex(random_bytes(10));
        $this->observer->query("CREATE TABLE `{$this->table}` (`id` INT PRIMARY KEY, `value` INT NOT NULL) ENGINE=InnoDB");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (1,0),(2,0)");
        $this->db->selectConnection('a');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->db !== null) {
                try { $this->db->recycleContext(); }
                finally { $this->db->reset(); }
            }
        } finally {
            if ($this->observer !== null) {
                if ($this->table !== null) { $this->observer->query("DROP TABLE IF EXISTS `{$this->table}`"); }
                $this->observer->close();
            }
        }
    }

    private function write(int $value, int $id = 1): void { $this->db->rawQuery("UPDATE `{$this->table}` SET `value`={$value} WHERE `id`={$id}"); }
    private function value(int $id = 1): int
    {
        $result = $this->observer->query("SELECT `value` FROM `{$this->table}` WHERE `id`={$id}");
        return (int) $result->fetch_assoc()['value'];
    }

    public function testFalseReturnCommitsAndBusinessExceptionRollsBack(): void
    {
        $this->assertFalse($this->db->transaction(function (): bool { $this->write(1); return false; }));
        $this->assertSame(1, $this->value());
        $failure = new \RuntimeException('business failed');
        try { $this->db->transaction(function () use ($failure): void { $this->write(2); throw $failure; }); $this->fail('Expected business error'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(1, $this->value());
    }

    public function testCaughtJoinedFailureCanBeCommittedByTheOuterOwner(): void
    {
        $failure = new \RuntimeException('inner failed');
        $this->db->transaction(function () use ($failure): void {
            $this->write(1);
            try { $this->db->transaction(function () use ($failure): void { $this->write(2); throw $failure; }); }
            catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        });
        $this->assertSame(2, $this->value());
    }

    public function testExistingManualTransactionRemainsOwnedByItsCaller(): void
    {
        $this->db->begin();
        $this->db->transaction(function (): void { $this->write(3); }, TransactionStartFlags::ReadOnly, 0.25);
        $this->assertTrue($this->db->isInTransaction());
        $this->assertSame(0, $this->value());
        $this->db->commit();
        $this->assertSame(3, $this->value());
    }

    public function testAtoBtoAWithACaughtInnerFailureCommitsBothConnections(): void
    {
        $failure = new \RuntimeException('joined A failed');
        $this->db->transaction(function () use ($failure): void {
            $this->write(1, 1);
            $this->db->withConnection('b', function () use ($failure): void {
                $this->db->transaction(function () use ($failure): void {
                    $this->write(4, 2);
                    try {
                        $this->db->withConnection('a', function () use ($failure): void {
                            $this->db->transaction(function () use ($failure): void { $this->write(2, 1); throw $failure; });
                        });
                    } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
                });
            });
        });
        $this->assertSame(2, $this->value(1));
        $this->assertSame(4, $this->value(2));
    }

    public function testCommittedBIsNotUndoneByARollbackOnA(): void
    {
        $failure = new \RuntimeException('A failed');
        try {
            $this->db->transaction(function () use ($failure): void {
                $this->write(1, 1);
                $this->db->withConnection('b', function (): void { $this->db->transaction(function (): void { $this->write(5, 2); }); });
                throw $failure;
            });
            $this->fail('Expected A error');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(0, $this->value(1));
        $this->assertSame(5, $this->value(2));
    }

    public function testRecyclingRollsBackTheDatabaseSessionBeforeAnotherBorrowerUsesIt(): void
    {
        $this->db->begin();
        $this->write(7);
        $this->db->recycleContext();
        $this->assertSame(0, $this->value());
        $this->write(8);
        $this->assertFalse($this->db->isInTransaction());
        $this->assertSame(8, $this->value());
    }
}
