<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Protocol\Connection as ProtocolConnection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class EntityReentryTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private EntityReentrySqliteConnection $connection;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite executes business SQL without MySQL writes.'); }
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE entity_reentry (id INTEGER PRIMARY KEY, a INTEGER, b INTEGER, secret TEXT)');
        $database->exec("INSERT INTO entity_reentry VALUES (1, 0, 0, 'private')");
        $this->connection = new EntityReentrySqliteConnection($database);
        $this->db = new FastDb();
        $this->bindConnection($this->connection);
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->singleton === null) { return; }
        if ($this->connection->database->inTransaction()) { $this->connection->database->rollBack(); }
        $this->connection->finishTransaction(false);
        $this->db->setOnQuery(static function (): void {});
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
        $this->singleton->setValue(null, $this->originalInstance);
    }

    private function bindConnection(Connection $connection): void
    {
        $connection->connectionName = 'default';
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($this->db, [Coroutine::getCid() => ['default' => $connection]]);
    }

    private function entity(): EntityReentryRecord { return new EntityReentryRecord(['id' => 1, 'a' => 0, 'b' => 0]); }

    private function baseline(EntityReentryRecord $entity): array
    {
        return (new \ReflectionProperty(AbstractEntity::class, 'entityState'))->getValue($entity)->baseline;
    }

    private function observe(string $mode, EntityReentryRecord $entity, callable $callback): void
    {
        if ($mode === 'global') { $this->db->setOnQuery($callback); }
        else { $entity->setOnQuery($callback); }
    }

    public static function observerModes(): array { return [['global'], ['entity']]; }
    public static function sameFieldCases(): array { return [['global', 2], ['entity', 2], ['global', 0], ['entity', 0]]; }

    public function testTransactionRestorationStaysIndependentAcrossModelsAndDoesNotRetainReleasedEntities(): void
    {
        $first = $this->entity();
        $second = new EntityReentryChildRecord(['id' => 1, 'a' => 4, 'b' => 0]);
        $released = $this->entity();
        $reference = \WeakReference::create($released);
        $this->assertTrue($this->db->begin());
        foreach ([$first, $second, $released] as $entity) {
            $entity->a++;
            $this->assertTrue($entity->update());
        }
        unset($entity, $released);
        gc_collect_cycles();
        $this->assertNull($reference->get());

        $this->assertTrue($this->db->rollback());
        $this->assertSame(0, $this->baseline($first)['a']);
        $this->assertSame(4, $this->baseline($second)['a']);
        $this->assertSame([1, 5], [$first->a, $second->a]);
        $this->assertTrue($second->update());
        $this->assertSame(5, $this->connection->row()['a']);
    }

    #[DataProvider('sameFieldCases')]
    public function testNestedWriteWinsAndCanReturnToTheOriginalValue(string $mode, int $nestedValue): void
    {
        $entity = $this->entity();
        $entity->a = 1;
        $entered = false;
        $this->observe($mode, $entity, function () use ($entity, $nestedValue, &$entered): void {
            if ($entered) { return; }
            $entered = true;
            $entity->a = $nestedValue;
            $this->assertTrue($entity->update());
        });

        $this->assertTrue($entity->update());
        $this->assertSame($nestedValue, $this->connection->row()['a']);
        $this->assertSame($nestedValue, $this->baseline($entity)['a']);
        $this->assertCount(2, $this->connection->writes);
        $entity->a = 1;
        $this->assertTrue($entity->update());
        $this->assertSame(1, $this->connection->row()['a']);
        $this->assertCount(3, $this->connection->writes);
    }

    #[DataProvider('observerModes')]
    public function testNestedDifferentFieldDoesNotSuppressTheOuterWrittenField(string $mode): void
    {
        $entity = $this->entity();
        $entity->a = 1;
        $entity->b = 7;
        $entity->queryLimit()->fields(['a']);
        $entered = false;
        $this->observe($mode, $entity, function () use ($entity, &$entered): void {
            if ($entered) { return; }
            $entered = true;
            $entity->b = 2;
            $entity->queryLimit()->fields(['b']);
            $this->assertTrue($entity->update());
        });

        $this->assertTrue($entity->update());
        $this->assertSame([1, 2], [$this->connection->row()['a'], $this->connection->row()['b']]);
        $this->assertSame([1, 2], [$this->baseline($entity)['a'], $this->baseline($entity)['b']]);
        $this->assertSame([1, 1], $this->connection->writes[0]);
        $this->assertSame([2, 1], $this->connection->writes[1]);
        $entity->a = $entity->b = 0;
        $this->assertTrue($entity->update());
        $this->assertSame([0, 0], [$this->connection->row()['a'], $this->connection->row()['b']]);
    }

    #[DataProvider('observerModes')]
    public function testCallbackMergeCompareRemainsTheNewestAcceptedBaseline(string $mode): void
    {
        $entity = $this->entity();
        $entity->a = $entity->b = 1;
        $this->observe($mode, $entity, static function () use ($entity): void { $entity->setData(['b' => 44], true); });

        $this->assertTrue($entity->update());
        $this->assertSame(44, $this->baseline($entity)['b']);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->connection->writes);
    }

    #[DataProvider('observerModes')]
    public function testObserverFailureDoesNotForgetAnAlreadySuccessfulWrite(string $mode): void
    {
        $entity = $this->entity();
        $entity->a = 1;
        $failure = new \RuntimeException('observer failed');
        $this->observe($mode, $entity, static function () use ($failure): void { throw $failure; });
        try { $entity->update(); $this->fail('Observer must throw'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }

        $this->assertSame(1, $this->connection->row()['a']);
        $this->assertSame(1, $this->baseline($entity)['a']);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->connection->writes);
    }

    public function testFailedWriteDoesNotAttachItsCompletionToAReusedBuilder(): void
    {
        $entity = $this->entity();
        $entity->a = 1;
        $entity->queryLimit()->where('missing_column', 1);
        $entered = false;
        $this->db->setOnQuery(function (QueryResult $result) use ($entity, &$entered): void {
            if ($entered || $result->getException() === null) { return; }
            $entered = true;
            $builder = $entity->queryLimit()->__getQueryBuilder();
            $builder->raw('UPDATE entity_reentry SET b=3 WHERE id=1');
            $this->db->query($builder);
        });

        try { $entity->update(); $this->fail('The invalid outer SQL must fail'); }
        catch (\PDOException) { $this->assertTrue($entered); }
        $this->assertSame([0, 3], [$this->connection->row()['a'], $this->connection->row()['b']]);
        $this->assertSame(0, $this->baseline($entity)['a']);
    }

    #[DataProvider('observerModes')]
    public function testRollbackAndNewTransactionKeepTheInnerWriteAndOldTokenOutcome(string $mode): void
    {
        $entity = $this->entity();
        $this->assertTrue($this->db->begin());
        $entity->a = 1;
        $entered = false;
        $this->observe($mode, $entity, function (QueryResult $result) use ($entity, &$entered): void {
            if ($entered || $result->getQueryBuilder() === null) { return; }
            $entered = true;
            $this->assertTrue($this->db->rollback());
            $this->assertSame(0, $this->baseline($entity)['a']);
            $this->assertTrue($this->db->begin());
            $entity->a = 2;
            $this->assertTrue($entity->update());
        });

        $this->assertFalse($entity->update());
        $this->assertSame(2, $this->connection->row()['a']);
        $this->assertSame(2, $this->baseline($entity)['a']);
        $this->assertTrue($this->db->rollback());
        $this->assertSame(0, $this->connection->row()['a']);
        $this->assertSame(0, $this->baseline($entity)['a']);
        $this->assertTrue($entity->update());
        $this->assertSame(2, $this->connection->row()['a']);
    }

    #[DataProvider('observerModes')]
    public function testFirstImplicitTransactionWriteIsRegisteredBeforeRollbackObservers(string $mode): void
    {
        $entity = $this->entity();
        $this->db->rawQuery('SET autocommit=0');
        $entity->a = 1;
        $entered = false;
        $this->observe($mode, $entity, function (QueryResult $result) use (&$entered): void {
            if ($entered || $result->getQueryBuilder() === null) { return; }
            $entered = true;
            $this->assertTrue($this->db->rollback());
        });

        $this->assertFalse($entity->update());
        $this->assertSame(0, $this->connection->row()['a']);
        $this->assertSame(0, $this->baseline($entity)['a']);
        $this->assertTrue($entity->update());
        $this->assertSame(1, $this->connection->row()['a']);
    }

    public static function listModes(): array { return [['global', false], ['entity', false], ['global', true], ['entity', true]]; }

    #[DataProvider('listModes')]
    public function testAllReentryPreservesTheCurrentQueryAndCallbackSelectionChanges(string $mode, bool $arrays): void
    {
        $entity = $this->entity();
        $entity->queryLimit()->fields(['id', 'a', 'b', 'secret'], $arrays)->hideFields('b')->persistFieldLimit();
        $entered = false;
        $this->observe($mode, $entity, function () use ($entity, &$entered): void {
            if ($entered) { return; }
            $entered = true;
            $entity->queryLimit()->hideFields(['b', 'secret']);
            $entity->all();
        });

        $item = $entity->all()->first();
        $this->assertSame(['id' => 1, 'a' => 0], $arrays ? $item : $item->toArray());
    }

    #[DataProvider('observerModes')]
    public function testNonReentrantCallbacksCanStillChangeCurrentSelection(string $mode): void
    {
        $entity = $this->entity();
        $entity->queryLimit()->persistFieldLimit();
        $this->observe($mode, $entity, static function () use ($entity): void { $entity->queryLimit()->hideFields('secret'); });
        $this->assertArrayNotHasKey('secret', $entity->all()->first()->toArray());
    }

    public function testProtocolCompletionAcceptsTheWriteBeforeGlobalObserverReentry(): void
    {
        $check = function (): void {
            [$socket, $peer] = swoole_coroutine_socketpair(AF_UNIX, SOCK_STREAM, 0);
            $config = new Config(['timeout' => 0.1]);
            $protocol = new ProtocolConnection($config);
            $stream = (new \ReflectionProperty($protocol, 'stream'))->getValue($protocol);
            (new \ReflectionProperty($stream, 'socket'))->setValue($stream, $socket);
            $state = (new \ReflectionProperty($protocol, 'state'))->getValue($protocol);
            $state->established();
            $state->applyServerStatus(2);
            $client = new Connection($config);
            (new \ReflectionProperty(Client::class, 'mysqlClient'))->setValue($client, $protocol);
            $this->bindConnection($client);
            $entity = $this->entity();
            $entered = false;
            $this->db->setOnQuery(function (QueryResult $result) use ($entity, &$entered): void {
                if ($entered) { return; }
                $entered = true;
                $this->assertSame(1, $result->getAffectedRows());
                $this->assertNull($result->getException());
                $this->assertSame(1, $this->baseline($entity)['a']);
                $entity->a = 0;
                $this->assertTrue($entity->update());
            });
            try {
                // 每条 UPDATE 都绑定字段值和主键；预处理元数据使用独立的报文序号。
                foreach ([7, 8] as $id) {
                    $packets = [[1, "\0" . pack('VvvCv', $id, 0, 2, 0, 0)], [2, 'parameter'], [3, 'parameter'],
                        [4, "\xfe\0\0\x02\0"], [1, "\0\x01\0\x02\0\0\0"]];
                    foreach ($packets as [$sequence, $payload]) {
                        $packet = substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;
                        $this->assertSame(strlen($packet), $peer->sendAll($packet, 0.1));
                    }
                }
                $entity->a = 1;
                $this->assertTrue($entity->update());
                $this->assertSame(0, $this->baseline($entity)['a']);
                $commands = [];
                for ($i = 0; $i < 6; $i++) {
                    $header = $peer->recvAll(4, 0.1);
                    $this->assertIsString($header);
                    $length = ord($header[0]) | ord($header[1]) << 8 | ord($header[2]) << 16;
                    $payload = $peer->recvAll($length, 0.1);
                    $this->assertIsString($payload);
                    $commands[] = ord($payload[0]);
                }
                $this->assertSame([0x16, 0x17, 0x19, 0x16, 0x17, 0x19], $commands);
            } finally {
                $client->close(); $peer->close(); $this->bindConnection($this->connection);
            }
        };
        if (Coroutine::getCid() < 0) { Coroutine\run($check); } else { $check(); }
    }
}

class EntityReentryRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public ?int $a;
    #[Property] public ?int $b;
    #[Property] public ?string $secret;
    public function tableName(): string { return 'entity_reentry'; }
}

class EntityReentryChildRecord extends EntityReentryRecord {}

/** 使用真实 SQL 和 FastDb 执行器，仅替换连接获取过程以避免联网。 */
class EntityReentrySqliteConnection extends Connection
{
    public array $writes = [];
    private bool $implicitTransactions = false;
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function row(): array { return $this->database->query('SELECT * FROM entity_reentry WHERE id=1')->fetch(\PDO::FETCH_ASSOC); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        if (str_starts_with($sql, 'UPDATE')) {
            if ($this->implicitTransactions && !$this->database->inTransaction()) {
                $this->database->beginTransaction(); $this->isInTransaction = true;
            }
            $this->writes[] = $parameters;
        }
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount();
        $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->lastAffectRows = $this->lastInsertId = 0;
        if ($query === 'SET autocommit=0') { $this->implicitTransactions = true; return true; }
        if (str_starts_with($query, 'START TRANSACTION')) {
            $this->database->beginTransaction(); $this->isInTransaction = true; return true;
        }
        if (str_starts_with($query, 'ROLLBACK')) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            $this->isInTransaction = false; return true;
        }
        if (str_starts_with($query, 'COMMIT')) {
            if ($this->database->inTransaction()) { $this->database->commit(); }
            $this->isInTransaction = false; return true;
        }
        throw new \LogicException('Unexpected SQL: ' . $query);
    }
}
