<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
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

    public function testRollbackKeepsSuccessfulBaselinesAndBusinessCanReloadBeforeRetry(): void
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
        $this->assertSame(1, $this->baseline($first)['a']);
        $this->assertSame(5, $this->baseline($second)['a']);
        $this->assertSame([1, 5], [$first->a, $second->a]);
        $before = count($this->connection->writes);
        $this->assertTrue($second->update());
        $this->assertCount($before, $this->connection->writes);
        $this->assertSame(0, $this->connection->row()['a']);
        // 业务显式重载数据库状态，再应用需要重试的修改。
        $second->setData($this->connection->row(), true);
        $second->a = 5;
        $this->assertTrue($second->update());
        $this->assertSame(5, $this->connection->row()['a']);
    }

    #[DataProvider('observerModes')]
    public function testObserverFailureLeavesTheWriteBaselineForBusinessToReload(string $mode): void
    {
        $entity = $this->entity();
        $entity->a = 1;
        $failure = new \RuntimeException('observer failed');
        $this->observe($mode, $entity, static function () use ($failure): void { throw $failure; });
        try { $entity->update(); $this->fail('Observer must throw'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }

        $this->assertSame(1, $this->connection->row()['a']);
        $this->assertSame(['a' => 1], $entity->getChanges());
        $entity->setData($this->connection->row(), true);
        $this->assertFalse($entity->hasChange());
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

    public static function listModes(): array { return [['global', false], ['entity', false], ['global', true], ['entity', true]]; }

    #[DataProvider('listModes')]
    public function testListObserverReadsTheExecutedResultBeforeOutputFiltering(string $mode, bool $arrays): void
    {
        $entity = $this->entity();
        $entity->queryLimit()->fields(['id', 'a', 'b', 'secret'], $arrays)->hideFields('secret')->persistFieldLimit();
        $this->observe($mode, $entity, function (QueryResult $result): void {
            $this->assertSame('private', $result->getResultOne()['secret']);
        });

        $item = $entity->all()->first();
        $this->assertSame(['id' => 1, 'a' => 0, 'b' => 0], $arrays ? $item : $item->toArray());
    }

}

class EntityReentryRecord extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?int $a;
    #[Property] public ?int $b;
    #[Property] public ?string $secret;
    public function tableName(): string { return 'entity_reentry'; }
}

class EntityReentryChildRecord extends EntityReentryRecord {}

/** 使用真实 SQL 和 FastDb 执行器，仅替换连接获取过程以避免联网。 */
class EntityReentrySqliteConnection extends Connection
{
    public function isInTransaction(): bool { return $this->database->inTransaction(); }
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
                $this->database->beginTransaction();
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
            $this->database->beginTransaction(); return true;
        }
        if (str_starts_with($query, 'ROLLBACK')) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            return true;
        }
        if (str_starts_with($query, 'COMMIT')) {
            if ($this->database->inTransaction()) { $this->database->commit(); }
            return true;
        }
        throw new \LogicException('Unexpected SQL: ' . $query);
    }
}
