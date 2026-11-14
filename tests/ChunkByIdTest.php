<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class ChunkByIdTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private KeysetChunkSqliteConnection $connection;
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite exercises generated SQL without MySQL writes.'); }
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE keyset_chunk_records (
            id INTEGER PRIMARY KEY, stable_key TEXT UNIQUE, name TEXT, status INTEGER,
            priority INTEGER, payload TEXT
        )');
        $this->connection = new KeysetChunkSqliteConnection($database);
        $this->connection->connectionName = 'default';
        $this->db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db,
            [Coroutine::getCid() => ['default' => $this->connection]]);
        $this->db->setOnQuery(function (QueryResult $result): void { $this->events[] = $result; });
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->originalInstance = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->singleton === null) { return; }
        $this->db->setOnQuery(static function (): void {});
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
        $this->singleton->setValue(null, $this->originalInstance);
    }

    private function seed(int $count): void
    {
        $insert = $this->connection->database->prepare('INSERT INTO keyset_chunk_records VALUES (?, ?, ?, 0, 0, ?)');
        for ($id = 1; $id <= $count; $id++) { $insert->execute([$id, sprintf('key-%02d', $id), 'row-' . $id, '[' . $id . ']']); }
    }

    private function pages(): array
    {
        return array_values(array_filter($this->connection->queries, static fn (array $query): bool => str_starts_with($query['sql'], 'SELECT')));
    }

    private function assertClean(AbstractEntity $entity): void
    {
        $this->assertNull($entity->queryLimit()->getFields());
        $this->assertNull($entity->queryLimit()->getHideFields());
        $builder = $entity->queryLimit()->__getQueryBuilder()->get($entity->tableName());
        $this->assertSame([], $builder->getLastBindParams());
        $this->assertSame([], $builder->getLastQueryOptions());
        $this->assertStringNotContainsString('WHERE', $builder->getLastPrepareQuery());
        $this->assertStringNotContainsString('ORDER BY', $builder->getLastPrepareQuery());
        $this->assertStringNotContainsString('LIMIT', $builder->getLastPrepareQuery());
    }

    public function testUpdatingProcessedRowsDoesNotSkipTheRemainingStatusMatchesAndUsesObservers(): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('status', 0);
        $entityEvents = [];
        $owner->setOnQuery(static function (QueryResult $result) use (&$entityEvents): void { $entityEvents[] = $result; });
        $seen = [];
        $delivered = $owner->chunkById(function (KeysetChunkRecord $row) use (&$seen): void {
            $seen[] = $row->id;
            $this->assertInstanceOf(ConvertList::class, $row->payload);
            $this->assertSame([$row->id], $row->payload->toArray());
            $row->status = 1;
            $this->assertTrue($row->update());
        }, 2);

        $this->assertSame(5, $delivered);
        $this->assertSame([1, 2, 3, 4, 5], $seen);
        $this->assertSame(5, (int)$this->connection->database->query('SELECT COUNT(*) FROM keyset_chunk_records WHERE status=1')->fetchColumn());
        $this->assertCount(3, $this->pages());
        $this->assertCount(3, $entityEvents);
        $this->assertCount(8, $this->events); // 读取三页数据，并执行五次实体更新。
        foreach ($this->pages() as $page) {
            $this->assertStringNotContainsString('SQL_CALC_FOUND_ROWS', $page['sql']);
            $this->assertNotContains('SQL_CALC_FOUND_ROWS', $page['options']);
        }
        $this->assertSame([], $this->connection->rawQueries);
        $this->assertClean($owner);
    }

    public function testOwnerChangesDoNotReapplyQueryLimitWhileAdvancingTheTemplate(): void
    {
        $this->seed(5);
        $owner = new KeysetScopedRecord(['status'=>0]);
        $seen = [];
        $this->assertSame(5, $owner->chunkById(static function (KeysetChunkRecord $row) use ($owner, &$seen): void {
            $seen[] = $row->id;
            $owner->status = 1;
        },2));
        $this->assertSame([1,2,3,4,5], $seen);
        $this->assertCount(3, $this->pages());
    }

    public function testDeletingProcessedRowsDoesNotSkipIds(): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $seen = [];
        $this->assertSame(5, $owner->chunkById(function (KeysetChunkRecord $row) use (&$seen): void {
            $seen[] = $row->id;
            $this->assertTrue($row->delete());
        }, 2));
        $this->assertSame([1, 2, 3, 4, 5], $seen);
        $this->assertSame(0, (int)$this->connection->database->query('SELECT COUNT(*) FROM keyset_chunk_records')->fetchColumn());
        $this->assertCount(3, $this->pages());
        $this->assertClean($owner);
    }

    public function testExistingOrConditionsStayGroupedAndOldOrderingAndOffsetDoNotControlTheCursor(): void
    {
        $this->seed(6);
        $this->connection->database->exec('UPDATE keyset_chunk_records SET status=1 WHERE id IN (2,3,5)');
        $this->connection->database->exec('UPDATE keyset_chunk_records SET priority=1 WHERE id IN (2,5)');
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('status', 0)->orWhere('priority', 1)->orderBy('name', 'DESC')->page(4, false, 1);
        $seen = [];
        $count = $owner->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void {
            if (count($seen) > 12) { throw new \RuntimeException('Cursor did not advance'); }
            $seen[] = $row->id;
        }, 2);
        $this->assertSame(5, $count);
        $this->assertSame([1, 2, 4, 5, 6], $seen);
        $this->assertSame([0, 1], $this->pages()[0]['parameters']);
        $this->assertSame([0, 1, 2], $this->pages()[1]['parameters']);
        $this->assertCount(3, $this->pages());
    }

    public function testChangingTheLastDeliveredEntityIdDoesNotChangeTheRawPageCursor(): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $seen = [];
        $this->assertSame(5, $owner->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void {
            $seen[] = $row->id;
            $row->id = 10000;
        }, 2));
        $this->assertSame([1, 2, 3, 4, 5], $seen);
        $this->assertSame([2], $this->pages()[1]['parameters']);
        $this->assertSame([4], $this->pages()[2]['parameters']);
    }

    public static function observerModes(): array { return [['global'], ['entity']]; }

    #[DataProvider('observerModes')]
    public function testObserverCanReadRowsWithoutChangingDeliveredIdsOrTheCursor(string $mode): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $first = true;
        $observer = static function (QueryResult $result) use (&$first): void {
            if (!$first) { return; }
            $first = false;
            $rows = $result->getResult();
            foreach ($rows as &$row) { $row['id'] += 100; }
            unset($row);
        };
        if ($mode === 'global') { $this->db->setOnQuery($observer); }
        else { $owner->setOnQuery($observer); }
        $seen = [];

        $this->assertSame(5, $owner->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void { $seen[] = $row->id; }, 2));
        $this->assertSame([1, 2, 3, 4, 5], $seen);
        $this->assertCount(3, $this->pages());
        $this->assertSame([2], $this->pages()[1]['parameters']);
        $this->assertSame([4], $this->pages()[2]['parameters']);
        $this->assertClean($owner);
    }

    public static function outputModes(): array { return [[false], [true]]; }

    #[DataProvider('outputModes')]
    public function testHiddenCursorStillAdvancesAndSelectedOutputKeepsItsForm(bool $arrays): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->fields(['id', 'name', 'payload'], $arrays)->hideFields('id')->persistFieldLimit();
        $seen = [];
        $this->assertSame(5, $owner->chunkById(function ($row) use ($arrays, &$seen): void {
            if ($arrays) {
                $this->assertIsArray($row);
                $data = $row;
            } else {
                $this->assertInstanceOf(KeysetChunkRecord::class, $row);
                $this->assertInstanceOf(ConvertList::class, $row->payload);
                $data = $row->toArray();
            }
            $this->assertArrayNotHasKey('id', $data);
            $this->assertSame(['name', 'payload'], array_keys($data));
            $seen[] = $data['name'];
        }, 2));
        $this->assertSame(['row-1', 'row-2', 'row-3', 'row-4', 'row-5'], $seen);
        $this->assertCount(3, $this->pages());
        $this->assertClean($owner);
    }

    public function testExplicitUniqueStringColumnWorksWithoutAPrimaryKey(): void
    {
        $this->seed(5);
        $owner = new KeysetChunkNoPrimaryRecord();
        $seen = [];
        $this->assertSame(5, $owner->chunkById(static function (KeysetChunkNoPrimaryRecord $row) use (&$seen): void { $seen[] = $row->stable_key; }, 2, 'stable_key'));
        $this->assertSame(['key-01', 'key-02', 'key-03', 'key-04', 'key-05'], $seen);
        $this->assertSame(['key-02'], $this->pages()[1]['parameters']);
    }

    public function testZeroAndNegativeIntegerCursorsAreNotMistakenForMissingValues(): void
    {
        $this->connection->database->exec("INSERT INTO keyset_chunk_records (id,name) VALUES (-1,'negative'),(0,'zero'),(1,'positive')");
        $seen = [];
        $this->assertSame(3, (new KeysetChunkRecord())->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void { $seen[] = $row->id; }, 2));
        $this->assertSame([-1, 0, 1], $seen);
        $this->assertSame([0], $this->pages()[1]['parameters']);
    }

    public function testEmptyStringIsAValidUniqueStringCursor(): void
    {
        $this->connection->database->exec("INSERT INTO keyset_chunk_records (id,stable_key) VALUES (1,''),(2,'a'),(3,'z')");
        $seen = [];
        $this->assertSame(3, (new KeysetChunkRecord())->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void { $seen[] = $row->stable_key; }, 1, 'stable_key'));
        $this->assertSame(['', 'a', 'z'], $seen);
        $this->assertSame([''], $this->pages()[1]['parameters']);
    }

    public function testEmptyTableDoesNotDeliverAnyRows(): void
    {
        $owner = new KeysetChunkRecord();
        $calls = 0;
        $this->assertSame(0, $owner->chunkById(static function () use (&$calls): void { $calls++; }, 2));
        $this->assertSame(0, $calls);
        $this->assertCount(1, $this->pages());
        $this->assertClean($owner);
    }

    public function testExactMultipleFetchesOneFinalEmptyPage(): void
    {
        $this->seed(4);
        $owner = new KeysetChunkRecord();
        $seen = [];
        $this->assertSame(4, $owner->chunkById(static function (KeysetChunkRecord $row) use (&$seen): void { $seen[] = $row->id; }, 2));
        $this->assertSame([1, 2, 3, 4], $seen);
        $this->assertCount(3, $this->pages());
    }

    public function testFalseStopsAndIncludesTheStoppingRowInTheCount(): void
    {
        $this->seed(5);
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('status', 0)->fields(['id'])->persistFieldLimit();
        $seen = [];
        $this->assertSame(3, $owner->chunkById(static function (KeysetChunkRecord $row) use (&$seen): bool {
            $seen[] = $row->id;
            return $row->id !== 3;
        }, 2));
        $this->assertSame([1, 2, 3], $seen);
        $this->assertCount(2, $this->pages());
        $this->assertClean($owner);
    }

    public function testOnlyStrictFalseStopsDelivery(): void
    {
        $this->seed(3);
        $seen = [];
        $this->assertSame(3, (new KeysetChunkRecord())->chunkById(static function (KeysetChunkRecord $row) use (&$seen): int { $seen[] = $row->id; return 0; }, 2));
        $this->assertSame([1, 2, 3], $seen);
    }

    public function testCallbackFailurePropagatesAndClearsConsumedQueryState(): void
    {
        $this->seed(3);
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('status', 0)->fields(['id'])->hideFields('payload')->persistFieldLimit();
        $failure = new \RuntimeException('callback failed');
        try {
            $owner->chunkById(static function () use ($failure): void { throw $failure; }, 2);
            $this->fail('Callback exception must propagate');
        } catch (\RuntimeException $caught) { $this->assertSame($failure, $caught); }
        $this->assertCount(1, $this->pages());
        $this->assertClean($owner);
    }

    public function testQueryFailureNotifiesBothObserversAndClearsQueryState(): void
    {
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('missing_column', 1)->fields(['id']);
        $entityEvents = [];
        $owner->setOnQuery(static function (QueryResult $result) use (&$entityEvents): void { $entityEvents[] = $result; });
        try { $owner->chunkById(static function (): void { self::fail('Failed SQL cannot deliver rows'); }, 2); $this->fail('SQL must fail'); }
        catch (\PDOException $error) {
            $this->assertSame($error, $this->events[0]->getException());
            $this->assertSame($error, $entityEvents[0]->getException());
        }
        $this->assertClean($owner);
    }

    public static function missingCursors(): array
    {
        return [['id', ['name']], ['id', ['id AS cursor']], ['stable_key', ['stable_key']]];
    }

    #[DataProvider('missingCursors')]
    public function testMissingOrNullLastCursorStopsBeforeDeliveringRows(string $column, array $fields): void
    {
        $this->seed(2);
        if ($column === 'stable_key') {
            $this->connection->database->exec('UPDATE keyset_chunk_records SET stable_key = NULL');
        }
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->fields($fields);
        $calls = 0;
        try {
            $owner->chunkById(static function () use (&$calls): bool { $calls++; return false; }, 2, $column);
            $this->fail('Missing or null cursor must fail');
        } catch (RuntimeError $error) {
            $this->assertStringContainsString('non-null cursor value for column: ' . $column, $error->getMessage());
        }
        $this->assertSame(0, $calls);
        $this->assertCount(1, $this->pages());
        $this->assertClean($owner);
    }

    public static function invalidSizes(): array { return [[0], [-1]]; }

    #[DataProvider('invalidSizes')]
    public function testInvalidChunkSizeFailsBeforeQueryAndStillConsumesState(int $size): void
    {
        $owner = new KeysetChunkRecord();
        $owner->queryLimit()->where('status', 0)->hideFields('payload');
        try { $owner->chunkById(static function (): void { self::fail('No callback expected'); }, $size); $this->fail('Invalid size must fail'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->connection->queries);
        $this->assertClean($owner);
    }

    public function testMissingPrimaryKeyRequiresAnExplicitColumn(): void
    {
        $owner = new KeysetChunkNoPrimaryRecord();
        try { $owner->chunkById(static function (): void {}, 2); $this->fail('No primary key must fail'); }
        catch (RuntimeError|\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->connection->queries);
        $this->assertClean($owner);
    }

    public static function invalidColumns(): array { return [[''], ['missing_column'], ['id AS other'], ['keyset_chunk_records.missing_column']]; }

    #[DataProvider('invalidColumns')]
    public function testCursorColumnMustEndInADeclaredPropertyName(string $column): void
    {
        $owner = new KeysetChunkRecord();
        try { $owner->chunkById(static function (): void {}, 2, $column); $this->fail('Invalid cursor column must fail'); }
        catch (RuntimeError|\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->connection->queries);
        $this->assertClean($owner);
    }
}

class KeysetChunkRecord extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?string $stable_key;
    #[Property] public ?string $name;
    #[Property] public ?int $status;
    #[Property] public ?int $priority;
    #[Property(convertObject: ConvertList::class)] public ?ConvertList $payload;
    public function tableName(): string { return 'keyset_chunk_records'; }
}

class KeysetChunkNoPrimaryRecord extends KeysetChunkRecord
{
    #[Property] public int $id;
}

/** 使用真实 SQLite、正常 FastDb 执行器、结果快照及公开查询回调。 */
class KeysetChunkSqliteConnection extends Connection
{
    public array $queries = [];
    public array $rawQueries = [];
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        $this->queries[] = ['sql' => $sql, 'parameters' => $parameters, 'options' => $builder->getLastQueryOptions()];
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount(); $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->rawQueries[] = $query;
        throw new \LogicException('Unexpected raw SQL while traversing keyset pages: ' . $query);
    }
}

class KeysetScopedRecord extends KeysetChunkRecord
{
    public function queryLimit(): Query { return parent::queryLimit()->where('status',$this->status ?? 0); }
}
