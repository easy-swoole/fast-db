<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Property;
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
        InsertStateRecord::$assignCalls = InsertStateRecord::$encodeCalls = InsertStateList::$valueCalls = 0;
        InsertStateRecord::$changeNameWhenEncoding = false;
        InsertStateRecord::$failEncoding = InsertStateList::$failEncoding = false;
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
            if (!str_starts_with($result->getQueryBuilder()?->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
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

    public function testGeneratedPrimaryKeyUsesItsAssignmentCallbackOnce(): void
    {
        AssignedInsertStateRecord::$assigns = 0;
        $entity = new AssignedInsertStateRecord(['name'=>'assigned']);
        $this->assertTrue($entity->insert());
        $this->assertSame(1, $entity->id);
        $this->assertSame(1, AssignedInsertStateRecord::$assigns);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->client->queries);
    }

    #[DataProvider('observerModes')]
    public function testObserverChangesRemainPendingAfterInsert(string $mode): void
    {
        $entity = new InsertStateRecord(['name' => 'before']);
        $this->observe($mode, $entity, function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getQueryBuilder()?->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
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
    public function testInsertAcceptsItsBaselineBeforeAnObserverReentersUpdate(string $mode): void
    {
        $entity = new InsertStateRecord(['name' => 'before']);
        $this->observe($mode, $entity, function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getQueryBuilder()?->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
            $entity->name = 'nested';
            $this->assertTrue($entity->update());
        });
        $this->assertTrue($entity->insert());
        $this->assertSame('nested', $entity->name);
        $this->assertSame('nested', $this->client->row()['name']);
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
        if ($builderFields) { $entity->queryLimit()->__getQueryBuilder()->fields(['name']); }
        else { $entity->queryLimit()->fields(['name']); }
        $this->assertTrue($entity->insert());
        $this->assertSame(1, $entity->id);
        $this->assertSame(0, $this->client->row()['score']);
        $this->assertSame(['score' => 9], $entity->getChanges());
        $entity->queryLimit()->fields(null);
        $this->assertTrue($entity->update());
        $this->assertSame([9, 1], $this->client->queries[1]['parameters']);
        $this->assertSame('inserted', $this->client->row()['name']);
        $this->assertSame(9, $this->client->row()['score']);
        $entity->id = 2; $entity->name = 'other';
        $this->expectException(\EasySwoole\FastDb\Exception\RuntimeError::class);
        $entity->update();
    }

    public function testInsertDoesNotRepeatAssignmentOrObjectEncoding(): void
    {
        $entity = new InsertStateRecord(['name' => 'converted', 'payload' => '[1]', 'items' => '[2]']);
        $items = $entity->items;
        InsertStateRecord::$assignCalls = InsertStateRecord::$encodeCalls = InsertStateList::$valueCalls = 0;
        $this->assertTrue($entity->insert());
        $this->assertSame(0, InsertStateRecord::$assignCalls);
        $this->assertSame(1, InsertStateRecord::$encodeCalls);
        $this->assertSame(1, InsertStateList::$valueCalls);
        $this->assertSame($items, $entity->items);
        $this->assertSame([1], $entity->payload);
        $this->assertSame('[1]', $this->client->row()['payload']);
        $this->assertTrue($entity->update());
        $this->assertCount(1, $this->client->queries);
    }

    #[DataProvider('writeFieldLimits')]
    public function testExcludedConvertersCannotBlockOutputInsertOrPartialUpdate(bool $builderFields): void
    {
        $entity = new InsertStateRecord(['name' => 'before', 'payload' => '[1]', 'items' => '[2]']);
        $entity->payload = [3]; $entity->items->append(4);
        InsertStateRecord::$encodeCalls = InsertStateList::$valueCalls = 0;
        InsertStateRecord::$failEncoding = InsertStateList::$failEncoding = true;
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
        $this->assertSame(0, InsertStateRecord::$encodeCalls);
        $this->assertSame(0, InsertStateList::$valueCalls);
        $this->assertNull($this->client->row()['payload']);
        $this->assertNull($this->client->row()['items']);
        // 被排除字段仍保留待更新状态，放开白名单后正常保存。
        InsertStateRecord::$failEncoding = InsertStateList::$failEncoding = false;
        $this->assertTrue($entity->update());
        $this->assertSame('[3]', $this->client->row()['payload']);
        $this->assertSame('[2,4]', $this->client->row()['items']);
        $this->assertSame('after', $this->client->row()['name']);
        $this->assertCount(3, $this->client->queries);
        $this->assertTrue($entity->update());
        $this->assertCount(3, $this->client->queries);
    }

    public function testHiddenFieldsStillParticipateInDatabaseWrites(): void
    {
        $entity = new InsertStateRecord(['name' => 'hidden', 'payload' => '[1]']);
        $entity->queryLimit()->hideFields(['name', 'payload']);
        $this->assertTrue($entity->insert());
        $this->assertSame('hidden', $this->client->row()['name']);
        $this->assertSame('[1]', $this->client->row()['payload']);
        $entity->payload = [2];
        $this->assertTrue($entity->update());
        $this->assertSame('[2]', $this->client->row()['payload']);
    }

    public function testSelectedConverterErrorsPropagateWithoutExecutingSql(): void
    {
        $entity = new InsertStateRecord(['name' => 'before', 'payload' => '[1]']);
        InsertStateRecord::$failEncoding = true;
        foreach (['toArray', 'insert', 'update'] as $operation) {
            $entity->payload = [2];
            $entity->queryLimit()->fields(['payload']);
            try { $entity->$operation(); $this->fail('Selected converter failure must propagate'); }
            catch (\RuntimeException $error) { $this->assertSame('payload encoding failed', $error->getMessage()); }
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

    public function testEncodingAnotherFieldCannotHideAChangeToAnAlreadyEncodedField(): void
    {
        $entity = new InsertStateRecord(['name' => 'before', 'payload' => '[1]']);
        InsertStateRecord::$changeNameWhenEncoding = true;
        $this->assertTrue($entity->insert());
        $this->assertSame(['before', '[1]'], $this->client->queries[0]['parameters']);
        $this->assertSame('changed-by-encoder', $entity->name);
        $this->assertSame('before', $this->client->row()['name']);
        InsertStateRecord::$changeNameWhenEncoding = false;
        $this->assertTrue($entity->update());
        $this->assertSame(['changed-by-encoder', 1], $this->client->queries[1]['parameters']);
        $this->assertSame('changed-by-encoder', $this->client->row()['name']);
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

    public function testSuccessfulDuplicateNoOpAcceptsTheWrittenValuesEvenWithZeroAffectedRows(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'same')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'old']);
        $entity->name = 'same';
        $entity->score = 7;
        // MySQL 的重复键更新未改变数据时，执行成功但影响行数为零。
        $this->client->nextNoOp = true;
        $this->assertTrue($entity->insert(['name']));
        $this->assertSame(['score' => 7], $entity->getChanges());
        $this->assertSame(0, $this->client->row(20)['score']);
        $this->assertTrue($entity->update());
        $this->assertSame([7, 20], $this->client->queries[1]['parameters']);
        $this->assertSame('same', $this->client->row(20)['name']);
        $this->assertSame(7, $this->client->row(20)['score']);
    }

    public function testExplicitDuplicateValueDoesNotAcceptTheEntityValue(): void
    {
        $this->client->database->exec("INSERT INTO insert_state (id,name) VALUES (20,'database')");
        $entity = new InsertStateRecord(['id' => 20, 'name' => 'old']);
        $entity->name = 'entity';
        $this->client->nextNoOp = true;
        $this->assertTrue($entity->insert(['name', 'name' => 'database']));
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
    public static int $assignCalls = 0;
    public static int $encodeCalls = 0;
    public static bool $changeNameWhenEncoding = false;
    public static bool $failEncoding = false;
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public ?string $name;
    #[Property] public ?int $score;
    #[Property(assignCall: new Call([self::class, 'decode']),
        toValue: new Call([self::class, 'encode'], [Call::PARAM_PROPERTY_VALUE, Call::PARAM_CURRENT_ENTITY]))]
    public ?array $payload;
    #[Property(convertObject: ConvertDateTime::class)] public ?ConvertDateTime $moment;
    #[Property(convertObject: InsertStateList::class)] public ?InsertStateList $items;
    public static function decode(?string $value): ?array { self::$assignCalls++; return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
    public static function encode(?array $value, self $entity): ?string
    {
        self::$encodeCalls++;
        if (self::$failEncoding) { throw new \RuntimeException('payload encoding failed'); }
        if (self::$changeNameWhenEncoding) { $entity->name = 'changed-by-encoder'; }
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
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
    #[Property(isPrimaryKey:true,convertObject:ConvertedKey::class)] public ?ConvertedKey $id;
    #[Property] public ?string $name;
    public function tableName(): string { return 'insert_state'; }
}
class AssignedInsertStateRecord extends AbstractEntity
{
    public static int $assigns = 0;
    #[Property(isPrimaryKey:true,assignCall:new Call([self::class,'assignId']))] public int $id;
    #[Property] public ?string $name;
    public static function assignId(int|string $value): int { self::$assigns++; return (int)$value; }
    public function tableName(): string { return 'insert_state'; }
}
