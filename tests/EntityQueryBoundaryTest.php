<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\ConvertedKey;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class EntityQueryBoundaryTest extends TestCase
{
    private FastDb $db;
    private QueryBoundaryConnection $client;
    private \ReflectionProperty $singleton;
    private mixed $previous;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite is required for offline SQL execution.'); }
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE query_boundary (id TEXT PRIMARY KEY, value REAL, `order` INTEGER, grp INTEGER)');
        $pdo->exec("INSERT INTO query_boundary VALUES ('is_object',1.5,4,1),('trim',2.5,6,1),('other',NULL,8,2)");
        $this->client = new QueryBoundaryConnection($pdo);
        $this->db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db,
            [Coroutine::getCid() => ['default' => $this->client]]);
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->previous = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->singleton)) { return; }
        $this->db->setOnQuery(static function (): void {});
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
        $this->singleton->setValue(null, $this->previous);
    }

    public function testFunctionNamesAreKeysAndCannotExpandTheWriteScope(): void
    {
        $this->assertSame('trim', QueryBoundaryRecord::findRecord('trim')->id);
        $this->assertSame(['is_object'], array_column(QueryBoundaryRecord::findAll('is_object', returnAsArray: true), 'id'));
        $this->assertSame(1, QueryBoundaryRecord::fastUpdate('is_object', ['order' => 99]));
        $this->assertSame(99, QueryBoundaryRecord::findRecord('is_object')->order);
        $this->assertSame(6, QueryBoundaryRecord::findRecord('trim')->order);
        $this->assertSame(8, QueryBoundaryRecord::findRecord('other')->order);
        $this->assertSame(0, QueryBoundaryRecord::fastUpdate('is_null', ['order' => 0]));
        $this->assertSame(0, QueryBoundaryRecord::fastDelete('is_null'));
        $this->assertSame(1, QueryBoundaryRecord::fastDelete('is_object'));
        $this->assertSame(['other', 'trim'], $this->client->database->query('SELECT id FROM query_boundary ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function zeroKeys(): array { return [['integer', 0], ['string', '0']]; }

    #[DataProvider('zeroKeys')]
    public function testZeroPrimaryKeySupportsEntityUpdateAndDelete(string $type, int|string $key): void
    {
        $this->client->database->exec("INSERT INTO query_boundary VALUES ('0',0,0,0)");
        $class = $type === 'integer' ? IntegerPrimaryKeyRecord::class : QueryBoundaryRecord::class;
        $entity = new $class(['id' => $key, 'order' => 0]);
        $entity->order = 10;
        $this->assertTrue($entity->update());
        $this->assertSame(10, QueryBoundaryRecord::findRecord('0')->order);
        $this->assertSame(4, QueryBoundaryRecord::findRecord('is_object')->order);
        $this->assertTrue($entity->delete());
        $this->assertNull(QueryBoundaryRecord::findRecord('0'));
        $this->assertSame(3, (int)$this->client->database->query('SELECT COUNT(*) FROM query_boundary')->fetchColumn());
    }

    #[DataProvider('zeroKeys')]
    public function testFastDeleteAcceptsBothZeroRepresentations(string $type, int|string $key): void
    {
        $this->client->database->exec("INSERT INTO query_boundary VALUES ('0',0,0,0)");
        $this->assertSame(1, QueryBoundaryRecord::fastDelete($key));
        $this->assertNull(QueryBoundaryRecord::findRecord('0'));
        $this->assertSame(3, (int)$this->client->database->query('SELECT COUNT(*) FROM query_boundary')->fetchColumn());
    }

    public function testFastDeleteStillIgnoresEmptyConditionsWithoutExecutingSql(): void
    {
        $this->assertSame(0, QueryBoundaryRecord::fastDelete([]));
        $this->assertSame(0, QueryBoundaryRecord::fastDelete(''));
        $this->assertSame([], $this->client->queries);
        $this->assertSame(3, (int)$this->client->database->query('SELECT COUNT(*) FROM query_boundary')->fetchColumn());
    }

    public static function missingKeys(): iterable
    {
        foreach (['update', 'delete'] as $operation) {
            foreach (['uninitialized', 'null', 'empty'] as $kind) {
                yield "$operation $kind" => [$operation, $kind];
            }
        }
    }

    #[DataProvider('missingKeys')]
    public function testMissingPrimaryKeysStillFailBeforeSql(string $operation, string $kind): void
    {
        $entity = $kind === 'uninitialized' ? new QueryBoundaryRecord() : new NullablePrimaryKeyRecord();
        if ($kind === 'empty') { $entity->id = ''; }
        $entity->order = 1;
        $this->expectException(\EasySwoole\FastDb\Exception\RuntimeError::class);
        $this->expectExceptionMessage('without primary key value');
        try { $entity->$operation(); }
        finally { $this->assertSame([], $this->client->queries); }
    }

    public function testConvertedPrimaryKeySupportsUpdateAndDeleteWithItsDatabaseValue(): void
    {
        $entity = new ConvertedKeyBoundaryRecord(['id' => 'trim', 'order' => 6]);
        $key = $entity->id;
        $entity->order = 10;
        $this->assertTrue($entity->update());
        $this->assertSame([10, 'trim'], $this->client->queries[0]['parameters']);
        $this->assertSame($key, $entity->id);
        $this->assertSame(10, QueryBoundaryRecord::findRecord('trim')->order);
        $this->assertSame(4, QueryBoundaryRecord::findRecord('is_object')->order);
        $this->assertTrue($entity->delete());
        $this->assertSame(['trim'], $this->client->queries[array_key_last($this->client->queries)]['parameters']);
        $this->assertNull(QueryBoundaryRecord::findRecord('trim'));
    }

    public function testAnEqualReplacementKeyCanUpdateAndDeleteWithoutChangingTheKey(): void
    {
        $entity = new ConvertedKeyBoundaryRecord(['id' => 'trim', 'order' => 6]);
        $key = new ConvertedKey('trim');
        $entity->id = $key;
        $entity->order = 11;
        $this->assertTrue($entity->update());
        $this->assertSame([11, 'trim'], $this->client->queries[0]['parameters']);
        $this->assertSame($key, $entity->id);
        $entity->id = new ConvertedKey('trim');
        $this->assertTrue($entity->delete());
        $this->assertSame(['trim'], $this->client->queries[1]['parameters']);
        $this->assertSame(2, (int)$this->client->database->query('SELECT COUNT(*) FROM query_boundary')->fetchColumn());
    }

    public static function changedConvertedKeys(): iterable
    {
        foreach (['update', 'delete'] as $operation) {
            foreach (['replace', 'mutate'] as $change) {
                yield "$operation $change" => [$operation, $change];
            }
        }
    }

    #[DataProvider('changedConvertedKeys')]
    public function testChangedConvertedPrimaryKeysStillFailBeforeSql(string $operation, string $change): void
    {
        $entity = new ConvertedKeyBoundaryRecord(['id' => 'trim', 'order' => 6]);
        if ($change === 'replace') { $entity->id = new ConvertedKey('other'); }
        else { $entity->id->value = 'other'; }
        $entity->order = 12;
        // 主键未参与字段写入时，也必须校验数据库定位值没有变化。
        $entity->queryLimit()->fields(['order']);
        $this->expectException(\EasySwoole\FastDb\Exception\RuntimeError::class);
        $this->expectExceptionMessage('Cannot change entity primary key: id');
        try { $entity->$operation(); }
        finally {
            $this->assertSame([], $this->client->queries);
            $this->assertSame(6, (int)$this->client->database->query("SELECT `order` FROM query_boundary WHERE id='trim'")->fetchColumn());
            $this->assertSame(8, (int)$this->client->database->query("SELECT `order` FROM query_boundary WHERE id='other'")->fetchColumn());
        }
    }

    public function testConvertedZeroKeyAndInsertedKeyKeepTheirComparisonBaseline(): void
    {
        $entity = new ConvertedKeyBoundaryRecord(['id' => '0', 'order' => 1]);
        $this->assertTrue($entity->insert());
        $entity->id = new ConvertedKey('0');
        $entity->order = 2;
        $this->assertTrue($entity->update());
        $this->assertSame(2, ConvertedKeyBoundaryRecord::findRecord('0')->order);
        $count = count($this->client->queries);
        $this->assertTrue($entity->update());
        $this->assertCount($count, $this->client->queries);
        $this->assertTrue($entity->delete());
        $this->assertNull(ConvertedKeyBoundaryRecord::findRecord('0'));
    }

    public function testConvertedKeysSupportLazyRelationsAndValueBasedCacheMatching(): void
    {
        $entity = new ConvertedKeyBoundaryRecord(['id' => 'trim', 'order' => 6]);
        $this->assertSame('trim', $entity->record()->id);
        $this->assertSame(['trim'], $this->client->queries[0]['parameters']);
        $entity->id = new ConvertedKey('trim');
        $this->assertSame('trim', $entity->record()->id);
        $this->assertCount(1, $this->client->queries);
        $this->assertSame('trim', $entity->records()->first()->id);
        $this->assertCount(2, $this->client->queries);
        $entity->id->value = 'other';
        $this->assertSame('other', $entity->record()->id);
        $this->assertCount(3, $this->client->queries);
    }

    public static function relationOutputs(): array { return [[false], [true]]; }

    #[DataProvider('relationOutputs')]
    public function testConvertedKeysSupportEntityAndArrayPreloading(bool $asArray): void
    {
        $owner = new ConvertedKeyBoundaryRecord();
        $owner->queryLimit()->fields(null, $asArray)->orderBy('id', 'ASC')->withRelations(['record', 'records']);
        $parents = $owner->all()->list();
        $this->assertCount(3, $this->client->queries);
        foreach ($parents as $parent) {
            if ($asArray) {
                $this->assertSame($parent['id'], $parent['record']['id']);
                $this->assertSame([$parent['id']], array_column($parent['records'], 'id'));
            } else {
                $parent->id = new ConvertedKey($parent->id->value);
                $this->assertSame($parent->id->value, $parent->record()->id);
                $this->assertSame($parent->id->value, $parent->records()->first()->id);
            }
        }
        $this->assertCount(3, $this->client->queries);
    }

    public static function aggregates(): array
    {
        return [['sum', 18.0], ['avg', 6.0], ['min', 4.0], ['max', 8.0]];
    }

    #[DataProvider('aggregates')]
    public function testAggregatesQuoteReservedAndQualifiedColumns(string $method, float $expected): void
    {
        $this->assertSame($expected, (new QueryBoundaryRecord())->$method('order'));
        $this->assertSame($expected, (new QueryBoundaryRecord())->$method('query_boundary.order'));
        $this->assertSame(['order' => $expected, 'query_boundary.order' => $expected],
            (new QueryBoundaryRecord())->$method(['order', 'query_boundary.order']));
    }

    public function testCountSupportsQualifiedColumnsAndKeepsTheOriginalKeys(): void
    {
        $this->assertSame(3, (new QueryBoundaryRecord())->count());
        $this->assertSame(3, (new QueryBoundaryRecord())->count('order'));
        $this->assertSame(2, (new QueryBoundaryRecord())->count('query_boundary.value'));
        $entity = new QueryBoundaryRecord();
        $entity->queryLimit()->fields(['order', 'query_boundary.value']);
        $this->assertSame(['order' => 3, 'query_boundary.value' => 2], $entity->count());
    }

    public function testSingleCountKeepsTrustedSqlExpressionsAndQuotedColumnReferences(): void
    {
        $entity = new QueryBoundaryRecord();
        $this->assertSame(3, $entity->count('1'));
        $this->assertSame(0, $entity->count('NULL'));
        $this->assertSame(2, $entity->count('DISTINCT grp'));
        $this->assertSame(2, $entity->count('CASE WHEN grp = 1 THEN id END'));
        $this->assertSame(3, $entity->count('`query_boundary`.`order`'));
    }

    public function testJoinedAggregatesUseTheSpecifiedAliasAndPreserveBindings(): void
    {
        $entity = new QueryBoundaryRecord();
        $entity->queryLimit()->join('query_boundary AS b', 'query_boundary.id = b.id', 'INNER')
            ->where('query_boundary.grp', 1)->fields(['query_boundary.id', 'b.value']);
        $this->assertSame(['query_boundary.id' => 2, 'b.value' => 2], $entity->count());
        $entity->queryLimit()->join('query_boundary AS b', 'query_boundary.id = b.id', 'INNER')->where('b.grp', 1);
        $this->assertSame(10.0, $entity->sum('b.order'));
        $this->assertSame([1], $this->client->queries[array_key_last($this->client->queries)]['parameters']);
    }

    public function testAggregationKeepsGroupingForceModeAndQueryCleanup(): void
    {
        $entity = new QueryBoundaryRecord();
        $entity->queryLimit()->where('grp', 1);
        $this->assertSame(['order' => 10, 'grp' => 2], $entity->sum(['order', 'grp'], force: false));
        $this->assertSame(18.0, $entity->sum('order'));
        $this->assertSame(10.0, $entity->sum('order', 'grp'));
        $entity->queryLimit()->fields(['order', 'grp']);
        $this->assertSame(['order' => 2, 'grp' => 2], $entity->count(null, 'grp'));
        $entity->queryLimit()->where('id', 'missing');
        $this->assertSame(0.0, $entity->sum('order'));
        $this->assertSame(3, $entity->count());
    }

    public static function paginationModes(): iterable
    {
        foreach (['global', 'entity'] as $observer) {
            foreach ([false, true] as $relation) {
                foreach ([false, true] as $total) {
                    yield "$observer relation=$relation total=$total" => [$observer, $relation, $total];
                }
            }
        }
    }

    #[DataProvider('paginationModes')]
    public function testTotalsFollowTheExecutedQueryWhenCallbacksReuseItsBuilder(string $observer, bool $relation, bool $total): void
    {
        $entity = new QueryBoundaryRecord(['id' => 'owner', 'grp' => 1]);
        $entity->queryLimit()->limit(1, $total)->returnAsArray();
        $builder = $entity->queryLimit()->__getQueryBuilder();
        $entered = false;
        $entityCountEvents = 0;
        $savedTotal = null;
        $callback = function (QueryResult $result) use ($builder, $total, &$entered, &$entityCountEvents, &$savedTotal): void {
            if ($result->getRawSql() === 'SELECT FOUND_ROWS() as count') { $entityCountEvents++; }
            if ($entered) { return; }
            $entered = true;
            $savedTotal = $result->getTotalCount();
            if ($total) {
                // 清除原构建器的总数选项，不得丢失外层已保存的总数。
                $builder->raw('SELECT 42 AS probe');
            } else {
                // 内层开启总数统计，不得为未请求总数的外层补查 FOUND_ROWS()。
                $builder->withTotalCount()->get('query_boundary', 1, ['id']);
            }
            $this->db->query($builder);
        };
        if ($observer === 'global') { $this->db->setOnQuery($callback); }
        else { $entity->setOnQuery($callback); }
        $list = $relation ? $entity->related() : $entity->all();
        $expectedTotal = $total ? ($relation ? 2 : 3) : null;
        $this->assertSame($expectedTotal, $savedTotal);
        $this->assertSame($expectedTotal, $list->totalCount());
        $this->assertCount(1, $list);
        $this->assertSame(1, $this->client->countQueries);
        if ($observer === 'entity') { $this->assertSame($total ? 1 : 0, $entityCountEvents); }
        $this->assertNull($entity->queryLimit()->getFields());
    }
}

class QueryBoundaryRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public string $id;
    #[Property] public ?float $value;
    #[Property] public int $order;
    #[Property] public int $grp;
    public function tableName(): string { return 'query_boundary'; }
    #[Relate(self::class, 'grp', 'grp', type: 'many')]
    public function related() { return $this->relateMany(); }
}

/** 执行实际 SQLite SQL，仅模拟 SQLite 不提供的 FOUND_ROWS() 结果。 */
class QueryBoundaryConnection extends Connection
{
    public array $queries = [];
    public int $countQueries = 0;
    private int $foundRows = 0;
    public function __construct(public \PDO $database) { parent::__construct(new Config()); $this->connectionName = 'default'; }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = str_replace('SQL_CALC_FOUND_ROWS ', '', $builder->getLastPrepareQuery());
        $parameters = $builder->getLastBindParams();
        $this->queries[] = ['sql' => $sql, 'parameters' => $parameters];
        if (in_array('SQL_CALC_FOUND_ROWS', $builder->getLastQueryOptions(), true)) {
            $all = $this->database->prepare(preg_replace('/\sLIMIT\s+\d+(?:\s*,\s*\d+)?\s*$/i', '', $sql));
            $all->execute($parameters);
            $this->foundRows = count($all->fetchAll(\PDO::FETCH_ASSOC));
        }
        $statement = $this->database->prepare($sql); $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount(); $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
    public function rawQuery(string $sql, ?float $timeout = null): bool|array
    {
        if ($sql !== 'SELECT FOUND_ROWS() as count') { throw new \LogicException('Unexpected raw SQL'); }
        $this->countQueries++;
        return [['count' => $this->foundRows]];
    }
}

class IntegerPrimaryKeyRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public int $order;
    public function tableName(): string { return 'query_boundary'; }
}

class NullablePrimaryKeyRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public ?string $id;
    #[Property] public int $order;
    public function tableName(): string { return 'query_boundary'; }
}

class ConvertedKeyBoundaryRecord extends AbstractEntity
{
    #[Property(isPrimaryKey: true, convertObject: ConvertedKey::class)] public ?ConvertedKey $id;
    #[Property] public int $order;
    public function tableName(): string { return 'query_boundary'; }
    #[Relate(QueryBoundaryRecord::class, 'id', type: 'one', cacheResult: true)]
    public function record() { return $this->relateOne(); }
    #[Relate(QueryBoundaryRecord::class, 'id', type: 'many', cacheResult: true)]
    public function records() { return $this->relateMany(); }
}
