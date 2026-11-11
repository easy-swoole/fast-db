<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequiredWhereTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private \PDO $database;
    private array $queries = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite is used to execute the generated parameterized SQL without MySQL writes.');
        }
        $this->database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE required_where_records (
            id INTEGER PRIMARY KEY, parent_id INTEGER, flag_a INTEGER, flag_b INTEGER, name TEXT
        )');
        $this->database->exec("INSERT INTO required_where_records VALUES
            (1, 1, 1, 0, 'own'), (2, 2, 1, 0, 'other'),
            (3, 1, 0, 1, 'own second'), (4, 2, 0, 1, 'other second')");

        // 执行实际的预处理 SQL；固定返回结果会掩盖 OR 优先级问题。
        $affected = 0;
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $client->expects($this->atLeastOnce())->method('query')->willReturnCallback(function (QueryBuilder $query) use (&$affected): bool|array {
            $sql = $query->getLastPrepareQuery();
            $parameters = $query->getLastBindParams();
            $this->queries[] = ['sql' => $sql, 'parameters' => $parameters];
            $statement = $this->database->prepare($sql);
            $statement->execute($parameters);
            $affected = $statement->rowCount();
            return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
        });
        $client->method('getLastAffectRows')->willReturnCallback(static function () use (&$affected): int { return $affected; });
        $client->method('getLastInsertId')->willReturn(0);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->singleton->setValue(null, $db);
    }

    protected function tearDown(): void
    {
        $this->singleton?->setValue(null, $this->originalInstance);
    }

    public static function orSources(): array
    {
        return [
            'orWhere wrapper' => ['wrapper'],
            'explicit OR conjunction' => ['conjunction'],
            'builder access through func' => ['func'],
            'bound SQL fragment' => ['bound-fragment'],
        ];
    }

    private function addOr(Query $query, string $source, int $secondValue = 1): void
    {
        match ($source) {
            'wrapper' => $query->where('flag_a', 1)->orWhere('flag_b', $secondValue),
            'conjunction' => $query->where('flag_a', 1)->where('flag_b', $secondValue, '=', 'OR'),
            'func' => $query->func(static function (QueryBuilder $builder) use ($secondValue): void {
                $builder->where('flag_a', 1)->orWhere('flag_b', $secondValue);
            }),
            'bound-fragment' => $query->where('flag_a=? OR flag_b=?', [1, $secondValue]),
        };
    }

    private function names(): array
    {
        return $this->database->query('SELECT id, name FROM required_where_records ORDER BY id')
            ->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    private function ids(): array
    {
        return $this->database->query('SELECT id FROM required_where_records ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    #[DataProvider('orSources')]
    public function testEntityUpdateCannotEscapeItsPrimaryKey(string $source): void
    {
        $entity = new RequiredWhereRecord(['id' => 1, 'name' => 'own']);
        $entity->name = 'changed';
        $this->addOr($entity->queryLimit(), $source);

        $this->assertTrue($entity->update());
        $this->assertSame([1 => 'changed', 2 => 'other', 3 => 'own second', 4 => 'other second'], $this->names());
        $this->assertSame(['changed', 1, 1, 1], $this->queries[0]['parameters']);
    }

    #[DataProvider('orSources')]
    public function testEntityDeleteCannotEscapeItsPrimaryKey(string $source): void
    {
        $entity = new RequiredWhereRecord(['id' => 1]);
        $this->addOr($entity->queryLimit(), $source);

        $this->assertTrue($entity->delete());
        $this->assertSame([2, 3, 4], $this->ids());
        $this->assertSame([1, 1, 1], $this->queries[0]['parameters']);
    }

    #[DataProvider('orSources')]
    public function testManyRelationCannotReturnAnotherParentsRecords(string $source): void
    {
        $parent = new RequiredWhereParent(['id' => 1]);
        $this->addOr($parent->queryLimit(), $source);
        $parent->queryLimit()->orderBy('id', 'ASC');

        $records = $parent->records()->list();

        $this->assertSame([1, 3], array_map(static fn (RequiredWhereRecord $record): int => $record->id, $records));
        $this->assertSame([1, 1], array_map(static fn (RequiredWhereRecord $record): int => $record->parent_id, $records));
        $this->assertSame([1, 1, 1], $this->queries[0]['parameters']);
    }

    #[DataProvider('orSources')]
    public function testOneRelationCannotSeeAnotherParentsMatchingRecord(string $source): void
    {
        $parent = new RequiredWhereParent(['id' => 1]);
        $this->addOr($parent->queryLimit(), $source, 0);

        $record = $parent->record();

        $this->assertInstanceOf(RequiredWhereRecord::class, $record);
        $this->assertSame(1, $record->id);
        $this->assertSame(1, $record->parent_id);
        $this->assertSame([1, 0, 1], $this->queries[0]['parameters']);
    }

    public function testUpdateGroupsOrAddedByItsLifecycleHook(): void
    {
        $entity = new RequiredWhereHookRecord(['id' => 1, 'name' => 'own']);
        $entity->name = 'changed';
        $entity->queryLimit()->where('flag_a', 1);

        $this->assertTrue($entity->update());
        $this->assertSame([1 => 'changed', 2 => 'other', 3 => 'own second', 4 => 'other second'], $this->names());
        $this->assertSame(['changed', 1, 1, 1], $this->queries[0]['parameters']);
    }

    public function testDeleteGroupsOrAddedByItsLifecycleHook(): void
    {
        $entity = new RequiredWhereHookRecord(['id' => 1]);
        $entity->queryLimit()->where('flag_a', 1);

        $this->assertTrue($entity->delete());
        $this->assertSame([2, 3, 4], $this->ids());
        $this->assertSame([1, 1, 1], $this->queries[0]['parameters']);
    }

    public function testFastWritesKeepTheirExplicitBatchOrSemantics(): void
    {
        $where = static function (QueryBuilder $query): void {
            $query->where('flag_a', 1)->orWhere('flag_b', 1);
        };

        $this->assertSame(4, RequiredWhereRecord::fastUpdate($where, ['name' => 'batch']));
        $this->assertSame([1 => 'batch', 2 => 'batch', 3 => 'batch', 4 => 'batch'], $this->names());
        $this->assertSame(['batch', 1, 1], $this->queries[0]['parameters']);
        $this->assertSame(4, RequiredWhereRecord::fastDelete($where));
        $this->assertSame([], $this->ids());
        $this->assertSame([1, 1], $this->queries[1]['parameters']);
    }
}

class RequiredWhereRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property]
    public ?int $parent_id;

    #[Property]
    public ?int $flag_a;

    #[Property]
    public ?int $flag_b;

    #[Property]
    public ?string $name;

    public function tableName(): string { return 'required_where_records'; }
}

#[OnUpdate('appendOr')]
#[OnDelete('appendOr')]
class RequiredWhereHookRecord extends RequiredWhereRecord
{
    protected function appendOr(): void
    {
        $this->queryLimit()->orWhere('flag_b', 1);
    }
}

class RequiredWhereParent extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    public function tableName(): string { return 'required_where_parents'; }

    #[Relate(RequiredWhereRecord::class, 'parent_id', type: RelationType::Many)]
    public function records(): ListResult { return $this->relateMany(); }

    #[Relate(RequiredWhereRecord::class, 'parent_id', type: RelationType::One)]
    public function record(): ?AbstractEntity { return $this->relateOne(); }
}
