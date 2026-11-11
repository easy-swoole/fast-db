<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class BulkWriteTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private BulkWriteFixtureConnection $connection;
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite executes write SQL without MySQL writes.'); }
        BulkWriteFixtureRecord::resetCounters();
        $this->connection = $this->newConnection('default');
        $this->db = new FastDb();
        $this->registerConnections(['default' => $this->connection]);
        $this->db->setOnQuery(function (QueryResult $result): void { $this->events[] = $result; });
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

    private function newConnection(string $name): BulkWriteFixtureConnection
    {
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (['bulk_write_records', 'bulk_write_explicit'] as $table) {
            $database->exec('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, name TEXT, count INTEGER, payload TEXT)');
        }
        $connection = new BulkWriteFixtureConnection($database);
        $connection->connectionName = $name;
        return $connection;
    }

    private function registerConnections(array $connections): void
    {
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db, [Coroutine::getCid() => $connections]);
    }

    private function row(int $id): array { return ['id' => $id, 'name' => 'raw-' . $id, 'count' => $id * 10, 'payload' => '[' . $id . ']']; }
    private function rows(int $count): array { $rows = []; for ($id = 1; $id <= $count; $id++) { $rows[] = $this->row($id); } return $rows; }
    private function stored(string $table = 'bulk_write_records'): array
    {
        return $this->connection->database->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }
    private function assertNoRowLifecycle(): void
    {
        $this->assertSame(0, BulkWriteFixtureRecord::$insertHooks);
        $this->assertSame(0, BulkWriteFixtureRecord::$assignCalls);
        $this->assertSame(0, BulkWriteFixtureRecord::$encodeCalls);
        $this->assertSame(0, BulkWriteFixtureList::$objectCalls);
        $this->assertSame(0, BulkWriteFixtureList::$valueCalls);
    }

    public function testInsertUsesOneStatementPerBatchAndRawDatabaseValues(): void
    {
        $rows = $this->rows(5);
        $localEvents = [];
        $affected = BulkWriteFixtureRecord::fastInsertAll($rows, onQuery: static function (QueryResult $result) use (&$localEvents): void { $localEvents[] = $result; }, batchSize: 2);
        $this->assertSame(5, $affected);
        $this->assertSame($rows, $this->stored());
        $this->assertCount(3, $this->connection->queries);
        $this->assertSame([8, 8, 4], array_map(static fn(array $query): int => count($query['parameters']), $this->connection->queries));
        $this->assertCount(3, $localEvents);
        $this->assertCount(3, $this->events);
        $this->assertNotSame($this->connection->builders[0], $this->connection->builders[1]);
        $this->assertNotSame($this->connection->builders[1], $this->connection->builders[2]);
        $this->assertSame(1, BulkWriteFixtureRecord::$constructed);
        $this->assertSame(1, BulkWriteFixtureRecord::$initializeHooks);
        $this->assertSame(1, BulkWriteFixtureRecord::$tableCalls);
        $this->assertSame([], $this->connection->rawQueries);
        $this->assertFalse($this->connection->database->inTransaction());
        $this->assertNoRowLifecycle();
    }

    public function testExplicitTableDoesNotConstructAModelOrRunAnyLifecycle(): void
    {
        $this->assertSame(2, BulkWriteFixtureSwitchingRecord::fastInsertAll($this->rows(2), 'bulk_write_explicit', batchSize: 1));
        $this->assertSame($this->rows(2), $this->stored('bulk_write_explicit'));
        $this->assertSame([], $this->stored());
        $this->assertSame(0, BulkWriteFixtureRecord::$constructed);
        $this->assertSame(0, BulkWriteFixtureRecord::$initializeHooks);
        $this->assertSame(0, BulkWriteFixtureRecord::$tableCalls);
        $this->assertNoRowLifecycle();
    }

    public function testEmptyArraysAndGeneratorsReturnZeroWithoutModelOrQueries(): void
    {
        $empty = static function (): \Generator { if (false) { yield []; } };
        $this->assertSame(0, BulkWriteFixtureSwitchingRecord::fastInsertAll([]));
        $this->assertSame(0, BulkWriteFixtureSwitchingRecord::fastInsertAll($empty()));
        $this->assertSame(0, BulkWriteFixtureSwitchingRecord::fastUpsert([], ['name']));
        $this->assertSame(0, BulkWriteFixtureSwitchingRecord::fastUpsert($empty(), ['name']));
        $this->assertSame([], $this->connection->queries);
        $this->assertSame([], $this->events);
        $this->assertSame(0, BulkWriteFixtureRecord::$constructed);
        $this->assertSame('default', $this->db->selectConnection());
    }

    public function testGeneratorIsSinglePassBoundedAndDoesNotCollapseRepeatedIterableKeys(): void
    {
        $yielded = 0;
        $starts = 0;
        $source = function () use (&$yielded, &$starts): \Generator {
            $starts++;
            for ($id = 1; $id <= 7; $id++) { $yielded++; yield 'same-key' => $this->row($id); }
        };
        $this->connection->beforeQuery = function (int $batch) use (&$yielded): void {
            $this->assertSame(min($batch * 3, 7), $yielded, 'Each full batch must execute before consuming the remaining generator.');
        };
        $this->assertSame(7, BulkWriteFixtureRecord::fastInsertAll($source(), batchSize: 3));
        $this->assertSame(1, $starts);
        $this->assertSame(7, $yielded);
        $this->assertSame($this->rows(7), $this->stored());
        $this->assertCount(3, $this->connection->queries);
    }

    public static function referenceColumnOrders(): array { return [[false], [true]]; }

    #[DataProvider('referenceColumnOrders')]
    public function testYieldedScalarReferencesAreSnapshottedRegardlessOfColumnOrder(bool $reordered): void
    {
        $source = function () use ($reordered): \Generator {
            $shared = 'at-first-yield';
            $first = $this->row(1); $first['name'] = &$shared;
            yield $first;
            $shared = 'at-second-yield';
            $second = $this->row(2); $second['name'] = &$shared;
            yield $reordered ? array_reverse($second, true) : $second;
            $shared = 'changed-before-flush';
            yield $this->row(3);
        };
        $this->assertSame(3, BulkWriteFixtureRecord::fastInsertAll($source(), batchSize: 3));
        $expected = $this->rows(3);
        $expected[0]['name'] = 'at-first-yield';
        $expected[1]['name'] = 'at-second-yield';
        $this->assertSame($expected, $this->stored());
        $this->assertCount(1, $this->connection->queries);
    }

    public function testDifferentColumnOrdersKeepTheirValuesAcrossBatchBoundaries(): void
    {
        $rows = $this->rows(3);
        $rows[1] = array_reverse($rows[1], true);
        $rows[2] = array_reverse($rows[2], true);
        $this->assertSame(3, BulkWriteFixtureRecord::fastInsertAll($rows, batchSize: 1));
        $this->assertSame($this->rows(3), $this->stored());
        $this->assertCount(3, $this->connection->queries);
    }

    public function testNullAndExpressionValuesReachTheBuilderWithoutEntityConversion(): void
    {
        $rows = [['id' => 1, 'name' => "O'Reilly", 'count' => QueryBuilder::rawExpression('? + ?', [3, 4]), 'payload' => null]];
        $this->assertSame(1, BulkWriteFixtureRecord::fastInsertAll($rows));
        $this->assertSame([['id' => 1, 'name' => "O'Reilly", 'count' => 7, 'payload' => null]], $this->stored());
        $this->assertSame([1, "O'Reilly", 3, 4, null], $this->connection->queries[0]['parameters']);
        $this->assertNoRowLifecycle();
    }

    public function testUpsertUsesIncomingColumnsAndKeepsOtherStoredColumns(): void
    {
        $this->connection->database->exec("INSERT INTO bulk_write_records VALUES (1,'old',5,'[99]')");
        $rows = $this->rows(2);
        $this->connection->affectedRows = [3]; // MySQL：一条重复记录发生变化（计 2 行），再新增一条记录（计 1 行）。
        $this->assertSame(3, BulkWriteFixtureRecord::fastUpsert($rows, ['name', 'count'], batchSize: 2));
        $this->assertSame([
            ['id' => 1, 'name' => 'raw-1', 'count' => 10, 'payload' => '[99]'],
            $rows[1],
        ], $this->stored());
        $query = $this->connection->queries[0];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $query['sql']);
        $this->assertMatchesRegularExpression('/`name`\s*=\s*`__mysqli_new`\.`name`/', $query['sql']);
        $this->assertMatchesRegularExpression('/`count`\s*=\s*`__mysqli_new`\.`count`/', $query['sql']);
        $this->assertSame([...array_values($rows[0]), ...array_values($rows[1])], $query['parameters']);
        $this->assertSame(1, BulkWriteFixtureRecord::$constructed);
        $this->assertNoRowLifecycle();
    }

    public function testUpsertKeepsFixedValuesAndExpressionsInTheirBindingOrder(): void
    {
        $this->connection->database->exec("INSERT INTO bulk_write_records VALUES (1,'old',5,'[99]')");
        $rows = $this->rows(2);
        $this->assertSame(2, BulkWriteFixtureRecord::fastUpsert($rows,
            ['name' => 'fixed-name', 'count' => QueryBuilder::rawExpression('`count` + ?', [7])], batchSize: 2));
        $this->assertSame([
            ['id' => 1, 'name' => 'fixed-name', 'count' => 12, 'payload' => '[99]'],
            $rows[1],
        ], $this->stored());
        $query = $this->connection->queries[0];
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $query['sql']);
        $this->assertStringContainsString('`count` = `count` + ?', $query['sql']);
        $this->assertSame([...array_values($rows[0]), ...array_values($rows[1]), 'fixed-name', 7], $query['parameters']);
        $this->assertNoRowLifecycle();
    }

    public function testFixedUpdateColumnDoesNotHaveToBeAnIncomingColumn(): void
    {
        $this->connection->database->exec("INSERT INTO bulk_write_records VALUES (1,'old',5,'[99]')");
        $this->assertSame(1, BulkWriteFixtureRecord::fastUpsert([['id' => 1, 'name' => 'incoming']], ['count' => 42]));
        $this->assertSame([['id' => 1, 'name' => 'old', 'count' => 42, 'payload' => '[99]']], $this->stored());
        $this->assertSame([1, 'incoming', 42], $this->connection->queries[0]['parameters']);
    }

    public function testUpsertStreamsItsDefault500RowBatchesAndPreservesIncomingColumnOrder(): void
    {
        $this->connection->database->exec("INSERT INTO bulk_write_records VALUES (1,'old',5,'[99]')");
        $yielded = 0;
        $source = function () use (&$yielded): \Generator {
            for ($id = 1; $id <= 501; $id++) {
                $yielded++;
                $row = $this->row($id);
                yield $id % 2 === 0 ? array_reverse($row, true) : $row;
            }
        };
        $this->connection->beforeQuery = function (int $batch) use (&$yielded): void { $this->assertSame($batch === 1 ? 500 : 501, $yielded); };
        $this->connection->affectedRows = [501, 1]; // 使用 MySQL 的更新/新增影响行数，不依赖 SQLite 的冲突记录计数。
        $this->assertSame(502, BulkWriteFixtureRecord::fastUpsert($source(), ['name', 'count']));
        $this->assertCount(2, $this->connection->queries);
        $this->assertSame([2000, 4], array_map(static fn(array $query): int => count($query['parameters']), $this->connection->queries));
        $stored = $this->stored();
        $this->assertCount(501, $stored);
        $this->assertSame(['id' => 1, 'name' => 'raw-1', 'count' => 10, 'payload' => '[99]'], $stored[0]);
        $this->assertSame($this->row(501), $stored[500]);
        $this->assertSame(1, BulkWriteFixtureRecord::$constructed);
        $this->assertNoRowLifecycle();
    }

    public function testUpsertCanMixIncomingColumnsAndFixedExpressions(): void
    {
        $this->connection->database->exec("INSERT INTO bulk_write_records VALUES (1,'old',5,'[99]')");
        $rows = $this->rows(2);
        $this->assertSame(2, BulkWriteFixtureRecord::fastUpsert($rows, ['name', 'count' => QueryBuilder::rawExpression('`count` + ?', [7])]));
        $this->assertSame([
            ['id' => 1, 'name' => 'raw-1', 'count' => 12, 'payload' => '[99]'],
            $rows[1],
        ], $this->stored());
        $this->assertSame([...array_values($rows[0]), ...array_values($rows[1]), 7], $this->connection->queries[0]['parameters']);
        $this->assertStringContainsString('`__mysqli_new`.`name`', $this->connection->queries[0]['sql']);
    }

    public function testImplicitUpsertColumnsMustAppearInTheIncomingRowsBeforeAnyWrite(): void
    {
        try { BulkWriteFixtureRecord::fastUpsert($this->rows(1), ['missing_column']); $this->fail('Incoming update columns must exist in the row schema'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->connection->queries);
        $this->assertSame([], $this->events);
    }

    public function testDriverAffectedRowsAreSummedWithoutFloatPrecisionLoss(): void
    {
        $this->connection->affectedRows = ['18446744073709551615', '18446744073709551615'];
        $sum = BulkWriteFixtureRecord::fastInsertAll($this->rows(2), batchSize: 1);
        $this->assertIsString($sum);
        $this->assertSame('36893488147419103230', $sum);
    }

    public function testIntOverflowPromotesToAnExactDecimalString(): void
    {
        $this->connection->affectedRows = [PHP_INT_MAX, 1];
        $sum = BulkWriteFixtureRecord::fastInsertAll($this->rows(2), batchSize: 1);
        $this->assertSame(PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648', $sum);
    }

    public function testZeroAffectedRowsAreNotReplacedByTheNumberOfInputRows(): void
    {
        $this->connection->affectedRows = [0, 0];
        $this->assertSame('0', (string)BulkWriteFixtureRecord::fastUpsert($this->rows(2), ['name'], batchSize: 1));
        $this->assertCount(2, $this->connection->queries);
    }

    public function testFailurePreservesTheFirstBatchAndNotifiesBothObserversWithoutReplacingTheError(): void
    {
        $failure = new \RuntimeException('second batch failed');
        $this->connection->failAt = 2;
        $this->connection->failure = $failure;
        $localEvents = [];
        $this->db->setOnQuery(function (QueryResult $result): void {
            $this->events[] = $result;
            if ($result->getException() !== null) { throw new \RuntimeException('global logger failed'); }
        });
        try {
            BulkWriteFixtureRecord::fastInsertAll($this->rows(5), onQuery: static function (QueryResult $result) use (&$localEvents): void {
                $localEvents[] = $result;
                if ($result->getException() !== null) { throw new \RuntimeException('local logger failed'); }
            }, batchSize: 2);
            $this->fail('The original second-batch error must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame($this->rows(2), $this->stored());
        $this->assertCount(2, $this->connection->queries);
        $this->assertCount(2, $this->events);
        $this->assertCount(2, $localEvents);
        $this->assertNull($localEvents[0]->getException());
        $this->assertSame($failure, $localEvents[1]->getException());
        $this->assertSame($failure, $this->events[1]->getException());
        $this->assertSame([], $this->connection->rawQueries);
    }

    public function testExternalTransactionCanRollBackEarlierSuccessfulBatches(): void
    {
        $this->assertTrue($this->db->begin());
        $failure = new \RuntimeException('second batch failed');
        $this->connection->failAt = 2;
        $this->connection->failure = $failure;
        try { BulkWriteFixtureRecord::fastInsertAll($this->rows(5), batchSize: 2); $this->fail('The second batch must fail'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame($this->rows(2), $this->stored());
        $this->assertTrue($this->connection->database->inTransaction());
        $this->assertTrue($this->db->rollback());
        $this->assertSame([], $this->stored());
        $this->assertCount(2, $this->connection->rawQueries);
        $this->assertStringStartsWith('START TRANSACTION', $this->connection->rawQueries[0]);
        $this->assertStringStartsWith('ROLLBACK', $this->connection->rawQueries[1]);
    }

    public function testSuccessfulObserverExceptionStopsWithoutRetryingTheCommittedBatch(): void
    {
        $failure = new \RuntimeException('observer failed after success');
        try {
            BulkWriteFixtureRecord::fastInsertAll($this->rows(5), onQuery: static function () use ($failure): void { throw $failure; }, batchSize: 2);
            $this->fail('Successful observer exception must propagate');
        } catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame($this->rows(2), $this->stored());
        $this->assertCount(1, $this->connection->queries);
        $this->assertSame([], $this->connection->rawQueries);
    }

    public function testObserverReentryDoesNotReplaceTheBatchAffectedRowSnapshot(): void
    {
        $observed = [];
        $this->assertSame(3, BulkWriteFixtureRecord::fastInsertAll($this->rows(3), onQuery: function (QueryResult $result) use (&$observed): void {
            $this->db->rawQuery('SELECT COUNT(*) AS count FROM bulk_write_records');
            $observed[] = $result->getAffectedRows();
        }, batchSize: 2));
        $this->assertSame([2, 1], $observed);
        $this->assertCount(2, $this->connection->queries);
        $this->assertCount(2, $this->connection->rawQueries);
        $this->assertCount(4, $this->events);
    }

    public function testGeneratorExceptionDoesNotSilentlyRollBackOrRetryItsPreviousBatch(): void
    {
        $failure = new \RuntimeException('generator failed');
        $source = function () use ($failure): \Generator { yield $this->row(1); yield $this->row(2); throw $failure; };
        try { BulkWriteFixtureRecord::fastInsertAll($source(), batchSize: 2); $this->fail('Generator exception must propagate'); }
        catch (\RuntimeException $error) { $this->assertSame($failure, $error); }
        $this->assertSame($this->rows(2), $this->stored());
        $this->assertCount(1, $this->connection->queries);
        $this->assertSame([], $this->connection->rawQueries);
    }

    public function testEntryConnectionStaysFixedWhileGeneratorAndObserversChangeTheVisibleSelection(): void
    {
        $other = $this->newConnection('other');
        $this->registerConnections(['default' => $this->connection, 'other' => $other]);
        $source = function (): \Generator {
            for ($id = 1; $id <= 3; $id++) { $this->db->selectConnection('other'); yield $this->row($id); }
        };
        $this->assertSame(3, BulkWriteFixtureRecord::fastInsertAll($source(), 'bulk_write_records', function (): void {
            $this->assertSame('other', $this->db->selectConnection());
            $this->db->selectConnection('other');
        }, 1));
        $this->assertSame($this->rows(3), $this->stored());
        $this->assertSame([], $other->queries);
        $this->assertSame('other', $this->db->selectConnection());
        foreach ($this->events as $event) { $this->assertSame('default', $event->getConnectionName()); }
    }

    public function testDefaultTableInitializationMayChooseTheFixedExecutionConnection(): void
    {
        $other = $this->newConnection('other');
        $this->registerConnections(['default' => $this->connection, 'other' => $other]);
        $source = function (): \Generator {
            yield $this->row(1);
            $this->db->selectConnection('default');
            yield $this->row(2);
        };
        $this->assertSame(2, BulkWriteFixtureSwitchingRecord::fastInsertAll($source(), batchSize: 1));
        $this->assertSame([], $this->connection->queries);
        $this->assertSame($this->rows(2), $other->database->query('SELECT * FROM bulk_write_records ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertCount(2, $other->queries);
        $this->assertSame('default', $this->db->selectConnection());
        $this->assertSame(1, BulkWriteFixtureRecord::$constructed);
        foreach ($this->events as $event) { $this->assertSame('other', $event->getConnectionName()); }
    }

    public static function invalidSizes(): array { return [[0], [-1]]; }

    #[DataProvider('invalidSizes')]
    public function testInvalidBatchSizeFailsImmediatelyEvenForEmptyInput(int $size): void
    {
        try { BulkWriteFixtureRecord::fastInsertAll([], batchSize: $size); $this->fail('Batch size must be positive'); }
        catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, BulkWriteFixtureRecord::$constructed);
        $this->assertSame([], $this->connection->queries);
    }

    public function testEmptyUpsertDoesNotConstructAModelOrValidateUnusedUpdateColumns(): void
    {
        $this->assertSame(0, BulkWriteFixtureRecord::fastUpsert([], []));
        $this->assertSame(0, BulkWriteFixtureRecord::$constructed);
        $this->assertSame([], $this->connection->queries);
    }

    public static function invalidRows(): array { return [[[]], [[1, 'value']], [[4 => 'value']]]; }

    #[DataProvider('invalidRows')]
    public function testDriverRejectsInvalidRowsBeforeWriting(mixed $invalid): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try { BulkWriteFixtureRecord::fastInsertAll([$invalid]); }
        finally { $this->assertSame([], $this->connection->queries); }
    }

    public static function batchSizes(): array { return [[1], [2]]; }

    #[DataProvider('batchSizes')]
    public function testIndependentBatchesCanUseDifferentColumnSets(int $batchSize): void
    {
        $third = $this->row(3);
        unset($third['payload']);
        $this->assertSame(3, BulkWriteFixtureRecord::fastInsertAll([$this->row(1), $this->row(2), $third], batchSize: $batchSize));
        $expected = $this->rows(3);
        $expected[2]['payload'] = null;
        $this->assertSame($expected, $this->stored());
        $this->assertCount((int) ceil(3 / $batchSize), $this->connection->queries);
    }

    public function testDriverRejectsDifferentColumnsInsideTheBatchBeforeAnyWrite(): void
    {
        $invalid = $this->row(2);
        unset($invalid['payload']);
        $invalid['unexpected'] = 'different field';
        try {
            BulkWriteFixtureRecord::fastInsertAll([$this->row(1), $invalid, $this->row(3)], batchSize: 3);
            $this->fail('A batch must not silently lose or shift fields');
        } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame([], $this->stored());
        $this->assertSame([], $this->connection->queries);
        $this->assertSame([], $this->events);
    }
}

#[OnInitialize('initialized')]
#[OnInsert('inserting')]
class BulkWriteFixtureRecord extends AbstractEntity
{
    public static int $constructed = 0;
    public static int $initializeHooks = 0;
    public static int $insertHooks = 0;
    public static int $assignCalls = 0;
    public static int $encodeCalls = 0;
    public static int $tableCalls = 0;
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property(assignCall: new Call([BulkWriteFixtureRecord::class, 'assignName']), toValue: new Call([BulkWriteFixtureRecord::class, 'encodeName']))]
    public ?string $name;
    #[Property] public ?int $count;
    #[Property(convertObject: BulkWriteFixtureList::class)] public ?BulkWriteFixtureList $payload;
    public function __construct(array|null $data = null) { self::$constructed++; parent::__construct($data); }
    public function tableName(): string { self::$tableCalls++; return 'bulk_write_records'; }
    protected function initialized(): void { self::$initializeHooks++; }
    protected function inserting(): void { self::$insertHooks++; }
    public static function assignName(mixed $value): string { self::$assignCalls++; return 'assigned:' . $value; }
    public static function encodeName(mixed $value): string { self::$encodeCalls++; return 'encoded:' . $value; }
    public static function resetCounters(): void
    {
        self::$constructed = self::$initializeHooks = self::$insertHooks = self::$assignCalls = self::$encodeCalls = self::$tableCalls = 0;
        BulkWriteFixtureList::$objectCalls = BulkWriteFixtureList::$valueCalls = 0;
    }
}

class BulkWriteFixtureSwitchingRecord extends BulkWriteFixtureRecord
{
    public function __construct(array|null $data = null) { parent::__construct($data); FastDb::getInstance()->selectConnection('other'); }
}

class BulkWriteFixtureList extends ConvertList
{
    public static int $objectCalls = 0;
    public static int $valueCalls = 0;
    public static function toObject(mixed $data): static { self::$objectCalls++; return parent::toObject($data); }
    public function toValue(): string { self::$valueCalls++; return parent::toValue(); }
}

/** 记录原始 MySQL SQL，仅在交给 SQLite 执行的副本中改写 upsert 语法。 */
class BulkWriteFixtureConnection extends Connection
{
    public function isInTransaction(): bool { return $this->database->inTransaction(); }
    public array $queries = [];
    public array $builders = [];
    public array $rawQueries = [];
    public array $affectedRows = [];
    public ?int $failAt = null;
    public ?\Throwable $failure = null;
    public mixed $beforeQuery = null;
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        $this->queries[] = ['sql' => $sql, 'parameters' => $parameters];
        $this->builders[] = $builder;
        $ordinal = count($this->queries);
        if ($this->beforeQuery !== null) { ($this->beforeQuery)($ordinal); }
        if ($this->failAt === $ordinal) { throw $this->failure ?? new \RuntimeException('Fixture query failure'); }
        $sqlite = preg_replace('/\s+AS\s+`__mysqli_new(?:_row)?`(?=\s+ON DUPLICATE KEY UPDATE)/i', '', $sql);
        $sqlite = str_replace(' ON DUPLICATE KEY UPDATE ', ' ON CONFLICT (`id`) DO UPDATE SET ', $sqlite);
        $sqlite = preg_replace('/`__mysqli_new(?:_row)?`\.`([^`]+)`/', 'excluded.`$1`', $sqlite);
        $sqlite = preg_replace('/VALUES\(`([^`]+)`\)/i', 'excluded.`$1`', $sqlite);
        $statement = $this->database->prepare($sqlite);
        $statement->execute($parameters);
        $this->lastAffectRows = $this->affectedRows === [] ? $statement->rowCount() : array_shift($this->affectedRows);
        $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->rawQueries[] = $query;
        $this->lastAffectRows = $this->lastInsertId = 0;
        if (str_starts_with($query, 'START TRANSACTION')) { $this->database->beginTransaction(); return true; }
        if (str_starts_with($query, 'ROLLBACK')) {
            if ($this->database->inTransaction()) { $this->database->rollBack(); }
            return true;
        }
        if (str_starts_with($query, 'COMMIT')) {
            if ($this->database->inTransaction()) { $this->database->commit(); }
            return true;
        }
        $statement = $this->database->query($query);
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
}
