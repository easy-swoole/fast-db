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
            'begin callback' => ['begin', 'START TRANSACTION', true, 0],
            'commit callback' => ['commit', 'COMMIT AND NO CHAIN NO RELEASE', false, 1],
            'rollback callback' => ['rollback', 'ROLLBACK AND NO CHAIN NO RELEASE', false, 0],
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
    public function testRealQueryTimeoutAndReconnectForBothProtocols(): void
    {
        foreach (['raw', 'prepared'] as $mode) {
            $this->db->rawQuery('SELECT 1'); // Acquire and connect before timing.
            $client = $this->db->currentConnection();
            $started = microtime(true);
            try {
                if ($mode === 'raw') {
                    $this->db->rawQuery('SELECT SLEEP(1)', 0.05);
                } else {
                    $query = new \EasySwoole\Mysqli\QueryBuilder();
                    $query->raw('SELECT SLEEP(?)', [1]);
                    $this->db->query($query, 0.05);
                }
                $this->fail('Expected query timeout');
            } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
                $this->assertLessThan(0.5, microtime(true) - $started);
                $this->assertFalse($client->mysqlClient()->isConnected());
                $this->assertInstanceOf(\EasySwoole\Mysqli\Exception\TimeoutException::class, $error->getPrevious());
                $this->assertSame($error->getPrevious()->getCode(), $error->getCode());
                $this->assertSame($mode === 'raw' ? 'SELECT SLEEP(1)' : 'SELECT SLEEP(?)', $error->getRawSql());
                $this->assertSame($error->getPrevious()->getMessage(), $error->getMessage());
                if ($mode === 'raw') {
                    $this->assertNull($error->getQueryBuilder());
                } else {
                    $this->assertNotSame($query, $error->getQueryBuilder());
                    $this->assertSame([1], $error->getQueryBuilder()->getLastBindParams());
                    $query->raw('SELECT 2');
                    $this->assertSame('SELECT SLEEP(?)', $error->getQueryBuilder()->getLastPrepareQuery());
                }
            }
            $this->assertSame(1, $this->db->rawQuery('SELECT 1 AS value')->getResultOne()['value']);
        }
    }

    public function testTimeoutInTransactionBlocksFurtherWritesAndRecycleIsClean(): void
    {
        $this->db->begin();
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $entity->value = 1;
        $this->assertTrue($entity->update());
        $client = $this->db->currentConnection();
        try {
            $this->db->rawQuery('SELECT SLEEP(1)', 0.05);
            $this->fail('Expected timeout');
        } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
            $this->assertTrue($client->mysqlClient()->isTransactionLost());
        }
        try {
            $this->write(2);
            $this->fail('Lost transaction must not reconnect and write');
        } catch (\EasySwoole\Mysqli\Exception\TransactionLostException $error) {
            $this->assertSame(0, $error->getCode());
        }
        $this->db->recycleContext();
        $this->assertFalse($client->isInTransaction);
        $this->assertTrue($this->db->begin());
        $this->write(3); // Waits for the disconnected session to release its row lock.
        $this->assertTrue($this->db->rollback());
        $this->assertSame(0, $this->value());
        $this->assertTrue($entity->update());
        $this->assertSame(1, $this->value());
    }

    public function testChainAndReleaseFlagsAgainstRealServer(): void
    {
        $flags = \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::ChainNoRelease;
        $this->db->begin();
        $this->write(1);
        $this->assertTrue($this->db->commit(null, $flags, 1.0));
        $this->assertTrue($this->db->isInTransaction());
        $this->assertTrue($this->db->currentConnection()->mysqlClient()->inTransaction());
        $this->assertSame(1, $this->value());
        $this->write(2);
        $this->assertTrue($this->db->rollback(null, $flags, 1.0));
        $this->assertTrue($this->db->isInTransaction());
        $this->assertSame(1, $this->value());
        $this->db->rollback();
        foreach (['commit', 'rollback'] as $operation) {
            $this->db->begin();
            $this->write(3);
            $client = $this->db->currentConnection();
            $this->assertTrue($this->db->$operation($client, \EasySwoole\Mysqli\Transaction\TransactionCompletionFlags::NoChainRelease, 1.0));
            $this->assertNull($client->mysqlClient());
            $this->assertFalse($client->isInTransaction);
            $this->assertSame(3, $this->value());
        }
    }

    public function testClientTimeoutDuringRowLockWaitRollsBackPendingWrites(): void
    {
        $this->db->begin();
        $this->write(1);
        $other = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
        try {
            $other->begin();
            $started = microtime(true);
            try {
                $other->rawQuery("UPDATE `{$this->table}` SET value = 2 WHERE id = 1", 0.05);
                $this->fail('Expected lock wait client timeout');
            } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
                $this->assertLessThan(0.5, microtime(true) - $started);
                $this->assertTrue($other->currentConnection()->mysqlClient()->isTransactionLost());
            }
            $other->recycleContext();
            $this->db->rollback();
            // Locks synchronize with server processing the closed session.
            $this->db->begin();
            $this->write(0);
            $this->db->commit();
            $this->assertSame(0, $this->value());
        } finally {
            $other->recycleContext();
            $other->reset();
        }
    }

    public function testRealDeadlockRestoresFastDbTransactionState(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2, 0)");
        $ready = new \Swoole\Coroutine\Channel(2);
        $go = new \Swoole\Coroutine\Channel(2);
        $done = new \Swoole\Coroutine\Channel(2);
        foreach ([[1, 2, 10], [2, 1, 20]] as [$first, $second, $value]) {
            \Swoole\Coroutine::create(function () use ($ready, $go, $done, $first, $second, $value): void {
                $db = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
                $result = [];
                try {
                    $db->begin();
                    $db->rawQuery("UPDATE `{$this->table}` SET value = {$value} WHERE id = {$first}");
                    $ready->push(true);
                    $go->pop(5.0);
                    $db->rawQuery("UPDATE `{$this->table}` SET value = {$value} WHERE id = {$second}", 3.0);
                    $db->commit();
                    $result = ['code' => 0, 'active' => $db->isInTransaction(), 'value' => $value];
                } catch (\Throwable $error) {
                    $result = ['code' => $error->getCode(), 'active' => $db->isInTransaction(), 'value' => $value];
                } finally {
                    $db->recycleContext();
                    $db->reset();
                    $done->push($result);
                }
            });
        }
        $this->assertTrue($ready->pop(5.0));
        $this->assertTrue($ready->pop(5.0));
        $go->push(true); $go->push(true);
        $results = [$done->pop(5.0), $done->pop(5.0)];
        $codes = array_column($results, 'code'); sort($codes);
        $this->assertSame([0, 1213], $codes);
        foreach ($results as $result) {
            $this->assertFalse($result['active']);
            if ($result['code'] === 0) {
                $rows = $this->observer->query("SELECT value FROM `{$this->table}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
                $this->assertSame([$result['value'], $result['value']], array_map(static fn($row) => (int) $row['value'], $rows));
            }
        }
    }


    public function testPoolExhaustionHonorsAcquisitionTimeoutAndRecovers(): void
    {
        $db = (new FastDb())->addDb(new Config(array_replace(MYSQL_CONFIG,
            ['minObjectNum' => 0, 'maxObjectNum' => 1, 'getObjectTimeout' => 0.05])));
        $ready = new \Swoole\Coroutine\Channel(1);
        $release = new \Swoole\Coroutine\Channel(1);
        $done = new \Swoole\Coroutine\Channel(1);
        \Swoole\Coroutine::create(function () use ($db, $ready, $release, $done): void {
            try {
                $db->rawQuery('SELECT 1');
                $ready->push(true);
                $release->pop(3.0);
            } finally { $db->recycleContext(); $done->push(true); }
        });
        try {
            $this->assertTrue($ready->pop(3.0));
            $started = microtime(true);
            try {
                $db->rawQuery('SELECT 1');
                $this->fail('Expected exhausted pool');
            } catch (\EasySwoole\FastDb\Exception\RuntimeError $error) {
                $this->assertStringContainsString('pool empty', $error->getMessage());
                $this->assertGreaterThanOrEqual(0.03, microtime(true) - $started);
                $this->assertLessThan(0.5, microtime(true) - $started);
            }
        } finally {
            $release->push(true);
            $this->assertTrue($done->pop(3.0));
        }
        try {
            $this->assertSame(1, $db->rawQuery('SELECT 1 AS value')->getResultOne()['value']);
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testServerLockWaitTimeoutKeepsTransactionAvailableForRollback(): void
    {
        $this->db->begin();
        $this->write(1);
        $other = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
        try {
            $other->rawQuery('SET SESSION innodb_lock_wait_timeout = 1');
            $other->begin();
            $started = microtime(true);
            try {
                $other->rawQuery("UPDATE `{$this->table}` SET value = 2 WHERE id = 1", 2.0);
                $this->fail('Expected server lock wait timeout');
            } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
                $this->assertSame(1205, $error->getCode());
                $this->assertGreaterThan(0.8, microtime(true) - $started);
                $this->assertLessThan(2.0, microtime(true) - $started);
                $this->assertTrue($other->isInTransaction());
                $this->assertTrue($other->currentConnection()->mysqlClient()->isConnected());
            }
            $this->assertTrue($other->rollback());
            $this->db->rollback();
            $this->assertSame(0, $this->value());
        } finally { $other->recycleContext(); $other->reset(); }
    }

    public function testTransactionCommandTimeoutAgainstRealServerThroughDelayedProxy(): void
    {
        foreach (['begin', 'commit', 'rollback'] as $operation) {
            $this->observer->query("UPDATE `{$this->table}` SET value = 0");
            $listener = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
            $this->assertTrue($listener->bind('127.0.0.1', 0));
            $this->assertTrue($listener->listen());
            $port = $listener->getsockname()['port'];
            $finished = new \Swoole\Coroutine\Channel(1);
            \Swoole\Coroutine::create(function () use ($listener, $finished, $operation): void {
                $peer = null; $upstream = null;
                $readerDone = new \Swoole\Coroutine\Channel(1);
                $delay = false;
                try {
                    $peer = $listener->accept(3.0);
                    $listener->close();
                    $upstream = new \Swoole\Coroutine\Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
                    if (!$upstream->connect(MYSQL_CONFIG['host'], MYSQL_CONFIG['port'], 3.0)) {
                        throw new \RuntimeException('Proxy upstream connection failed');
                    }
                    \Swoole\Coroutine::create(function () use ($peer, $upstream, &$delay, $readerDone): void {
                        try {
                            while (($data = $upstream->recv(65536, 3.0)) !== false && $data !== '') {
                                if ($delay) { $delay = false; \Swoole\Coroutine::sleep(0.2); }
                                if ($peer->sendAll($data, 1.0) === false) { break; }
                            }
                        } finally { $readerDone->push(true); }
                    });
                    while (($header = $peer->recvAll(4, 3.0)) !== false && strlen($header) === 4) {
                        $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
                        $payload = $peer->recvAll($length, 3.0);
                        if ($payload === false || strlen($payload) !== $length) { break; }
                        $prefix = $operation === 'begin' ? 'START TRANSACTION' : strtoupper($operation);
                        if (str_starts_with($payload, "\x03" . $prefix)) { $delay = true; }
                        if ($upstream->sendAll($header . $payload, 1.0) === false) { break; }
                    }
                    $upstream->close();
                    $readerDone->pop(3.0);
                } finally {
                    $peer?->close(); $upstream?->close();
                    if (!$listener->isClosed()) { $listener->close(); }
                    $finished->push(true);
                }
            });
            $db = (new FastDb())->addDb(new Config(array_replace(MYSQL_CONFIG,
                ['host' => '127.0.0.1', 'port' => $port, 'minObjectNum' => 0])));
            $loggedFailure = null;
            $db->isEnableQueryStack(true)->setOnQuery(static function (QueryResult $result) use (&$loggedFailure): void {
                if ($result->getException() !== null) {
                    $loggedFailure = $result;
                    throw new \RuntimeException('timeout logger failed');
                }
            });
            try {
                $db->rawQuery('SELECT 1');
                if ($operation !== 'begin') {
                    $db->begin();
                    $db->rawQuery("UPDATE `{$this->table}` SET value = 1 WHERE id = 1");
                }
                $started = microtime(true);
                try {
                    $db->$operation(timeout: 0.05);
                    $this->fail('Expected transaction command timeout');
                } catch (\EasySwoole\FastDb\Exception\TimeoutException $error) {
                    $this->assertGreaterThanOrEqual(0.03, microtime(true) - $started);
                    $this->assertLessThan(0.15, microtime(true) - $started);
                    $this->assertFalse($db->currentConnection()->mysqlClient()->isConnected());
                    $this->assertSame($operation === 'begin' ? 'START TRANSACTION' : strtoupper($operation) . ' AND NO CHAIN NO RELEASE', $error->getRawSql());
                    $this->assertSame($error, $loggedFailure->getException());
                    $this->assertSame($error->getRawSql(), $loggedFailure->getRawSql());
                    $this->assertNull($loggedFailure->getResult());
                    $this->assertSame($error->getRawSql(), $db->getQueryStack(-1)->rawQuery);
                }
                $db->recycleContext();
                $this->assertTrue($finished->pop(3.0));
                // Response delay happens after forwarding the command: COMMIT may succeed on the server.
                $this->assertSame($operation === 'commit' ? 1 : 0, $this->value());
            } finally { $db->recycleContext(); $db->reset(); }
        }
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
