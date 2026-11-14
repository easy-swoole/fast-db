<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\AutoIncrement;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Tests\Model\ConvertedKey;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class InsertStateTest extends TestCase
{
    private \ReflectionProperty $singleton;
    private mixed $previous;
    private FastDb $db;
    private InsertStateConnection $client;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite is required for offline SQL execution.'); }
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE insert_state (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, score INTEGER DEFAULT 0, payload TEXT, moment TEXT, items TEXT)');
        $this->client = new InsertStateConnection($pdo);
        $this->client->connectionName = 'default';
        $this->db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => ['default' => $this->client]]);
        $this->singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->previous = $this->singleton->getValue();
        $this->singleton->setValue(null, $this->db);
        InsertStateList::$valueCalls = 0;
        InsertStateList::$failEncoding = false;
    }

    protected function tearDown(): void
    {
        if (!isset($this->singleton)) { return; }
        $this->db->setOnQuery(static function (): void {});
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, []);
        $this->singleton->setValue(null, $this->previous);
    }

    public static function observerModes(): array { return [['global'], ['entity']]; }

    private function observe(string $mode, InsertStateRecord $entity, callable $callback): void
    {
        if ($mode === 'global') { $this->db->setOnQuery($callback); }
        else { $entity->setOnQuery($callback); }
    }

    #[DataProvider('observerModes')]
    public function testConvertedAutoKeyIsReadyBeforeObserversAndSupportsUpdateAndDelete(string $mode): void
    {
        $entity = new ConvertedInsertStateRecord(['name'=>'before']);
        $observer = function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
            $this->assertInstanceOf(ConvertedKey::class, $entity->id);
            $this->assertSame('1', $entity->id->value);
            $entity->name = 'nested';
            $this->assertTrue($entity->update());
        };
        if ($mode === 'global') { $this->db->setOnQuery($observer); }
        else { $entity->setOnQuery($observer); }
        $this->assertTrue($entity->insert());
        $this->assertSame('nested', $this->client->row()['name']);
        $this->assertTrue($entity->update());
        $this->assertCount(2, $this->client->queries);
        $entity->id = new ConvertedKey('1');
        $entity->name = 'after';
        $this->assertTrue($entity->update());
        $this->assertSame('after', $this->client->row()['name']);
        $this->assertTrue($entity->delete());
        $this->assertSame(0, (int)$this->client->database->query('SELECT COUNT(*) FROM insert_state')->fetchColumn());
        $this->assertCount(4, $this->client->queries);
    }

    public function testGeneratedPrimaryKeyUsesItsObjectConverterOnce(): void
    {
        CountingInsertKey::$conversions = 0;
        $entity = new CountingKeyInsertStateRecord(['name'=>'assigned']);
        $this->assertTrue($entity->insert());
        $this->assertSame('1', $entity->id->value);
        $this->assertSame(1, CountingInsertKey::$conversions);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->client->queries);
    }

    public function testAutoIncrementCanBeDeclaredWithoutAPrimaryKey(): void
    {
        $entity = new class (['name' => 'generated']) extends AbstractEntity {
            #[Property]
            #[AutoIncrement]
            public int $id;
            #[Property]
            public string $name;
            public function tableName(): string { return 'insert_state'; }
        };

        $this->assertTrue($entity->insert());
        $this->assertSame(1, $entity->id);
        $this->assertFalse($entity->hasChange('id'));
    }

    public function testEntityCannotDeclareTwoPrimaryKeyFields(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('can not duplicate define primary key');
        new class extends InsertStateRecord {
            #[Property]
            #[PrimaryKey]
            public int $other;
        };
    }

    public function testPrimaryKeyRequiresAMappedProperty(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('PrimaryKey requires a public non-static Property field');
        new class extends InsertStateRecord {
            #[PrimaryKey]
            public int $unmapped;
        };
    }

    public function testEntityCannotDeclareTwoAutoIncrementFields(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('Only one AutoIncrement field');
        new class extends InsertStateRecord {
            #[Property]
            #[AutoIncrement]
            public int $first;
            #[Property]
            #[AutoIncrement]
            public int $second;
        };
    }

    public function testAutoIncrementRequiresAMappedProperty(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('AutoIncrement requires a public non-static Property field');
        new class extends InsertStateRecord {
            #[AutoIncrement]
            public int $unmapped;
        };
    }

    #[DataProvider('observerModes')]
    public function testObserverChangesRemainPendingAfterInsert(string $mode): void
    {
        $entity = new InsertStateRecord(['name' => 'before']);
        $this->observe($mode, $entity, function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
            $this->assertSame(1, $entity->id ?? null, 'The generated key must be available before public observers.');
            $entity->name = 'after';
        });
        $this->assertTrue($entity->insert());
        $this->assertSame('after', $entity->name);
        $this->assertSame('before', $this->client->row()['name']);
        $this->assertTrue($entity->update());
        $this->assertSame('after', $this->client->row()['name']);
        $this->assertCount(2, $this->client->queries);
        $this->assertTrue($entity->update());
        $this->assertCount(2, $this->client->queries);
    }

    #[DataProvider('observerModes')]
    public function testInsertClearsItsWriteFieldsAndAcceptsItsBaselineBeforeAnObserverReentersUpdate(string $mode): void
    {
        $entity = new InsertStateRecord(['name' => 'before']);
        $entity->queryLimit()->fields(['name'])->hideFields('payload');
        $this->observe($mode, $entity, function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
            $this->assertNull($entity->queryLimit()->getFields());
            $this->assertSame(['payload'], $entity->queryLimit()->getHideFields());
            $entity->score = 50;
            $this->assertTrue($entity->update());
        });
        $entity->name = 'inserted';
        $this->assertTrue($entity->insert());
        $this->assertSame('inserted', $this->client->row()['name']);
        $this->assertSame(50, $this->client->row()['score']);
        $this->assertSame([50, 1], $this->client->queries[1]['parameters']);
        $this->assertTrue($entity->update());
        $this->assertCount(2, $this->client->queries);
        $entity->name = 'before';
        $this->assertTrue($entity->update());
        $this->assertSame('before', $this->client->row()['name']);
        $this->assertCount(3, $this->client->queries);
    }

    public function testDateObjectIdentityTimezoneAndTimestampSurviveInsert(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $date = new ConvertDateTime('2026-11-10 12:00:00', new \DateTimeZone('Asia/Shanghai'));
            $timestamp = $date->getTimestamp();
            $entity = new InsertStateRecord(['name' => 'date', 'moment' => $date]);
            $this->assertTrue($entity->insert());
            $this->assertSame($date, $entity->moment);
            $this->assertSame('Asia/Shanghai', $entity->moment->getTimezone()->getName());
            $this->assertSame($timestamp, $entity->moment->getTimestamp());
            $this->assertSame('2026-11-10 12:00:00', $this->client->row()['moment']);
            $this->assertTrue($entity->update());
            $this->assertCount(1, $this->client->queries);
        } finally { date_default_timezone_set($timezone); }
    }

    public static function writeFieldLimits(): array { return ['entity' => [false], 'builder' => [true]]; }

    #[DataProvider('writeFieldLimits')]
    public function testFilteredInsertAcceptsOnlyWrittenFieldsAndTheGeneratedPrimaryKey(bool $builderFields): void
    {
        $entity = new InsertStateRecord(['name' => 'before', 'score' => 1]);
        $entity->name = 'inserted'; $entity->score = 9;
        $held = $entity->queryLimit()->hideFields('payload')->persistFieldLimit();
        if ($builderFields) { $held->returnAsArray()->__getQueryBuilder()->fields(['name']); }
        else { $held->fields(['name'], true); }
        $builder = $held->__getQueryBuilder();
        $this->assertTrue($entity->insert());
        $this->assertSame(1, $entity->id);
        $this->assertSame(0, $this->client->row()['score']);
        $this->assertSame(['score' => 9], $entity->getChanges());
        $next = $entity->queryLimit();
        $this->assertNotSame($held, $next);
        $this->assertSame($builder, $held->__getQueryBuilder());
        $this->assertNull($next->getFields());
        $this->assertFalse($next->isPersistFieldLimit());
        $this->assertSame(['payload'], $next->getHideFields());
        $this->assertArrayNotHasKey('payload', $entity->toArray());
        $this->assertSame(9, $entity->toArray()['score']);
        $this->assertTrue($entity->update());
        $this->assertSame([9, 1], $this->client->queries[1]['parameters']);
        $this->assertSame('inserted', $this->client->row()['name']);
        $this->assertSame(9, $this->client->row()['score']);
        $entity->id = 2; $entity->name = 'other';
        $this->expectException(\EasySwoole\FastDb\Exception\RuntimeError::class);
        $entity->update();
    }

    public function testInsertEncodesEachObjectOnceAndPreservesItsIdentity(): void
    {
        $entity = new InsertStateRecord(['name' => 'converted', 'payload' => '[1]', 'items' => '[2]']);
        $items = $entity->items;
        InsertStateList::$valueCalls = 0;
        $this->assertTrue($entity->insert());
        $this->assertSame(2, InsertStateList::$valueCalls);
        $this->assertSame($items, $entity->items);
        $this->assertSame([1], $entity->payload->toArray());
        $this->assertSame('[1]', $this->client->row()['payload']);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->client->queries);
    }

    #[DataProvider('writeFieldLimits')]
    public function testExcludedConvertersCannotBlockOutputInsertOrPartialUpdate(bool $builderFields): void
    {
        $entity = new InsertStateRecord(['name' => 'before', 'payload' => '[1]', 'items' => '[2]']);
        $entity->setData(['payload' => [3]]); $entity->items->append(4);
        InsertStateList::$valueCalls = 0;
        InsertStateList::$failEncoding = true;
        $entity->queryLimit()->hideFields(['payload', 'items']);
        $this->assertSame(['id' => null, 'name' => 'before', 'score' => null, 'moment' => null], $entity->toArray());
        $entity->queryLimit()->hideFields([])->fields(['name']);
        $this->assertSame(['name' => 'before'], $entity->toArray());
        if ($builderFields) { $entity->queryLimit()->fields(null)->__getQueryBuilder()->fields(['name']); }
        $this->assertTrue($entity->insert());
        $entity->name = 'after';
        if ($builderFields) { $entity->queryLimit()->__getQueryBuilder()->fields(['name']); }
        else { $entity->queryLimit()->fields(['name']); }
        $this->assertTrue($entity->update());
        $this->assertSame(0, InsertStateList::$valueCalls);
        $this->assertNull($this->client->row()['payload']);
        $this->assertNull($this->client->row()['items']);
        // 被排除字段仍保留待更新状态，放开白名单后正常保存。
        InsertStateList::$failEncoding = false;
        $this->assertTrue($entity->update());
        $this->assertSame('[3]', $this->client->row()['payload']);
        $this->assertSame('[2,4]', $this->client->row()['items']);
        $this->assertSame('after', $this->client->row()['name']);
        $this->assertCount(3, $this->client->queries);
        $this->assertTrue($entity->update());
        $this->assertCount(3, $this->client->queries);
    }

    public function testPersistFieldLimitOnlyRestrictsSerialization(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id, name, score) VALUES (1, 'before', 0)");
        $owner = new InsertStateRecord();
        $owner->queryLimit()->where('id', 1)->fields(['id', 'name', 'payload'])
            ->hideFields('payload')->persistFieldLimit();
        $entity = $owner->all()->first();

        $this->assertSame(['id' => 1, 'name' => 'before'], $entity->toArray());
        $this->assertSame(['id' => 1, 'name' => 'before'], json_decode(json_encode($entity, JSON_THROW_ON_ERROR), true));
        $entity->score = 60;
        $this->assertTrue($entity->update());
        $this->assertSame(60, $this->client->row()['score']);
        $this->assertSame([60, 1], $this->client->queries[1]['parameters']);

        $entity = $owner->queryLimit()->fields(['id', 'name'])->persistFieldLimit()->returnEntity()->all()->first();
        $entity->name = 'after';
        $entity->score = 70;
        $entity->queryLimit()->fields(['name']);
        $this->assertSame(['name' => 'after'], $entity->toArray());
        $this->assertTrue($entity->update());
        $this->assertSame('after', $this->client->row()['name']);
        $this->assertSame(60, $this->client->row()['score']);
        $this->assertSame(['score' => 70], $entity->getChanges());
    }

    public function testHiddenFieldsStillParticipateInDatabaseWrites(): void
    {
        $entity = new InsertStateRecord(['name' => 'hidden', 'payload' => '[1]']);
        $entity->queryLimit()->hideFields(['name', 'payload']);
        $this->assertTrue($entity->insert());
        $this->assertSame('hidden', $this->client->row()['name']);
        $this->assertSame('[1]', $this->client->row()['payload']);
        $entity->setData(['payload' => [2]]);
        $this->assertTrue($entity->update());
        $this->assertSame('[2]', $this->client->row()['payload']);
    }

    public function testSelectedConverterErrorsPropagateWithoutExecutingSql(): void
    {
        $entity = new InsertStateRecord(['id' => 1, 'name' => 'before', 'payload' => '[1]']);
        InsertStateList::$failEncoding = true;
        foreach (['toArray', 'insert', 'update'] as $operation) {
            $entity->setData(['payload' => [2]]);
            $entity->queryLimit()->fields(['payload']);
            try { $entity->$operation(); $this->fail('Selected converter failure must propagate'); }
            catch (\RuntimeException $error) { $this->assertSame('items encoding failed', $error->getMessage()); }
        }
        $this->assertCount(0, $this->client->queries);
    }

    public function testChangedConvertedObjectsAreEncodedOnceAndAcceptedAsTheBaseline(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,items) VALUES (1,'[1]')");
        $entity = new InsertStateRecord(['id' => 1, 'items' => '[1]']);
        $entity->items->append(2);
        InsertStateList::$valueCalls = 0;
        $this->assertTrue($entity->update());
        $this->assertSame(1, InsertStateList::$valueCalls);
        $this->assertSame(['[1,2]', 1], $this->client->queries[0]['parameters']);
        $this->assertSame('[1,2]', $this->client->row()['items']);
        InsertStateList::$valueCalls = 0;
        $this->assertTrue($entity->update());
        $this->assertSame(1, InsertStateList::$valueCalls);
        $this->assertCount(1, $this->client->queries);
    }

    public function testEncodingFailureDoesNotWriteAnEmptyStringOrChangeTheBusinessValue(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,items) VALUES (1,'[1]')");
        $entity = new InsertStateRecord(['id' => 1, 'items' => '[1]']);
        $entity->items->append(NAN);
        try { $entity->update(); $this->fail('Invalid JSON must fail before SQL'); }
        catch (\JsonException) {}
        $this->assertSame([], $this->client->queries);
        $this->assertSame('[1]', $this->client->row()['items']);
        $this->assertTrue(is_nan($entity->items->toArray()[1]));
        // 无效业务数据由调用方修正，修正后可以继续正常保存。
        $entity->setData(['items' => '[2]']);
        $this->assertTrue($entity->update());
        $this->assertSame('[2]', $this->client->row()['items']);
    }

    public function testSqlFailureDoesNotAcceptFieldsAndKeepsObserverChangesForRetry(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'before')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'before']);
        $entity->name = 'pending';
        $failure = new \RuntimeException('insert SQL failed');
        $this->client->nextFailure = $failure;
        $entity->setOnQuery(function (QueryResult $result) use ($failure, $entity): void {
            $this->assertSame($failure, $result->getException());
            $entity->name = 'after-failure';
            throw new \RuntimeException('observer failure');
        });
        try { $entity->insert(); $this->fail('SQL failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame('after-failure', $entity->name);
        $entity->setOnQuery(null);
        $this->assertTrue($entity->update());
        $this->assertSame(['after-failure', 20], $this->client->queries[1]['parameters']);
        $this->assertSame('after-failure', $this->client->row(20)['name']);
    }

    public function testFalseInsertResultIsNotAcceptedJustBecauseDuplicateColumnsWereRequested(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'before')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'before']);
        $entity->name = 'pending';
        $this->client->nextFalse = true;
        $this->assertFalse($entity->insert(['name']));
        $this->assertTrue($entity->update());
        $this->assertSame(['pending', 20], $this->client->queries[1]['parameters']);
        $this->assertSame('pending', $this->client->row(20)['name']);
    }

    public static function duplicateOptions(): array { return ['insert argument' => [false], 'builder' => [true]]; }

    #[DataProvider('duplicateOptions')]
    public function testSuccessfulDuplicateNoOpAcceptsTheWrittenValuesEvenWithZeroAffectedRows(bool $builderOption): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'same')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'old']);
        $entity->name = 'same';
        $entity->score = 7;
        // MySQL 的重复键更新未改变数据时，执行成功但影响行数为零。
        $this->client->nextNoOp = true;
        if ($builderOption) {
            $entity->queryLimit()->func(static fn (QueryBuilder $builder) => $builder->onDuplicate(['name']));
        }
        $this->assertTrue($entity->insert($builderOption ? null : ['name']));
        $this->assertSame(['score' => 7], $entity->getChanges());
        $this->assertSame(0, $this->client->row(20)['score']);
        $this->assertTrue($entity->update());
        $this->assertSame([7, 20], $this->client->queries[1]['parameters']);
        $this->assertSame('same', $this->client->row(20)['name']);
        $this->assertSame(7, $this->client->row(20)['score']);
    }

    public static function writeOperations(): array { return [['insert'], ['update']]; }

    #[DataProvider('writeOperations')]
    public function testFieldsExcludedByTableNameStayPending(string $operation): void
    {
        $entity = new LateFieldSelectionRecord(['name' => 'before', 'score' => 0, 'items' => '[1]']);
        if ($operation === 'update') {
            $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (1,'before')");
            $entity->setData(['id' => 1], true);
        }
        $entity->setData(['name' => 'saved', 'score' => 9]);
        InsertStateList::$valueCalls = 0;
        $this->assertTrue($entity->{$operation}());
        $this->assertSame(0, InsertStateList::$valueCalls);
        $this->assertSame('saved', $this->client->row()['name']);
        $this->assertSame(0, $this->client->row()['score']);
        $this->assertSame(['score' => 9], $entity->getChanges());
        $entity->limitWriteFields = false;
        $this->assertTrue($entity->update());
        $this->assertSame(9, $this->client->row()['score']);
    }

    public function testDuplicateOptionsCannotBeConfiguredThroughBothEntryPoints(): void
    {
        $entity = new InsertStateRecord(['name' => 'incoming']);
        $entity->queryLimit()->func(static fn (QueryBuilder $builder) => $builder->onDuplicate(['name']));
        try {
            $entity->insert(['name' => 'other']);
            $this->fail('Expected conflicting duplicate-update configuration to fail');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('onDuplicate()', $error->getMessage());
        }
        $this->assertSame([], $this->client->queries);
    }

    public function testExplicitDuplicateValueDoesNotAcceptTheEntityValue(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'database')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'old']);
        $entity->name = 'entity';
        $this->client->nextNoOp = true;
        $this->assertTrue($entity->insert(['name' => 'database']));
        $this->assertSame(['name' => 'entity'], $entity->getChanges());
        $this->assertSame('database', $this->client->row(20)['name']);
    }

    public function testObserverExceptionDoesNotForgetAnAlreadySuccessfulInsert(): void
    {
        $entity = new InsertStateRecord(['name' => 'before']);
        $failure = new \RuntimeException('observer failed');
        $this->db->setOnQuery(static function () use ($failure): void { throw $failure; });
        try { $entity->insert(); $this->fail('Observer failure must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame(1, $entity->id ?? null);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->client->queries);
        $this->assertSame('before', $this->client->row()['name']);
    }

    public function testSerializationCannotRemoveTheConditionFromTheFollowingUpdate(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name,score) VALUES (1,'before',0)");
        $entity = new InsertStateRecord(['id' => 1, 'name' => 'before', 'score' => 0]);
        $entity->queryLimit()->where('score', 9);
        $entity->toArray();
        json_encode($entity, JSON_THROW_ON_ERROR);
        $entity->name = 'blocked';
        $this->assertFalse($entity->update());
        $this->assertSame('before', $this->client->row()['name']);
        $this->assertSame(['blocked', 9, 1], $this->client->queries[0]['parameters']);
    }
}

class InsertStateRecord extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?string $name;
    #[Property] public ?int $score;
    #[Property(convertObject: InsertStateList::class)] public ?InsertStateList $payload;
    #[Property(convertObject: ConvertDateTime::class)] public ?ConvertDateTime $moment;
    #[Property(convertObject: InsertStateList::class)] public ?InsertStateList $items;
    public function tableName(): string { return 'insert_state'; }
}

class InsertStateList extends ConvertList
{
    public static int $valueCalls = 0;
    public static bool $failEncoding = false;
    public function toValue(): string
    {
        self::$valueCalls++;
        if (self::$failEncoding) { throw new \RuntimeException('items encoding failed'); }
        return parent::toValue();
    }
}

/** 通过正常 FastDb 执行路径运行 SQLite SQL，模拟 MySQL 无变化结果及指定单次失败。 */
class LateFieldSelectionRecord extends InsertStateRecord
{
    public bool $limitWriteFields = true;

    public function tableName(): string
    {
        if ($this->limitWriteFields) {
            $this->queryLimit()->__getQueryBuilder()->fields(['name']);
        }
        return parent::tableName();
    }
}

class InsertStateConnection extends Connection
{
    public array $queries = [];
    public ?\Throwable $nextFailure = null;
    public bool $nextFalse = false;
    public bool $nextNoOp = false;
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery(); $parameters = $builder->getLastBindParams();
        $this->queries[] = ['sql' => $sql, 'parameters' => $parameters];
        $this->lastAffectRows = $this->lastInsertId = 0;
        if ($this->nextFailure !== null) { $failure = $this->nextFailure; $this->nextFailure = null; throw $failure; }
        if ($this->nextFalse) { $this->nextFalse = false; return false; }
        if ($this->nextNoOp) { $this->nextNoOp = false; return true; }
        $statement = $this->database->prepare($sql); $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount();
        if (str_starts_with($sql, 'INSERT')) { $this->lastInsertId = (int)$this->database->lastInsertId(); }
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
    public function row(int $id = 1): array { return $this->database->query('SELECT * FROM insert_state WHERE id=' . $id)->fetch(\PDO::FETCH_ASSOC); }
}

class ConvertedInsertStateRecord extends AbstractEntity
{
    #[Property(convertObject:ConvertedKey::class)]
    #[PrimaryKey]
    public ?ConvertedKey $id;
    #[Property] public ?string $name;
    public function tableName(): string { return 'insert_state'; }
}
class CountingKeyInsertStateRecord extends AbstractEntity
{
    #[Property(convertObject: CountingInsertKey::class)]
    #[PrimaryKey]
    public ?CountingInsertKey $id;
    #[Property] public ?string $name;
    public function tableName(): string { return 'insert_state'; }
}

class CountingInsertKey extends ConvertedKey
{
    public static int $conversions = 0;
    public static function toObject(mixed $data): object
    {
        self::$conversions++;
        return parent::toObject($data);
    }
}
