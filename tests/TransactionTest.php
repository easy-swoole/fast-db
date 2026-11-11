<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Config;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\ConvertedKey;
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

    public function testMysqlConvertedAutoKeyIsAvailableForObserverUpdatesAndDeletion(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id INT NOT NULL AUTO_INCREMENT");
        $entity = new ConvertedKeyMysqlEntity(['value'=>7]);
        $this->db->setOnQuery(function (QueryResult $result) use ($entity): void {
            if (!str_starts_with($result->getQueryBuilder()?->getLastPrepareQuery() ?? '', 'INSERT')) { return; }
            $this->assertInstanceOf(ConvertedKey::class, $entity->id);
            $this->assertSame('2', $entity->id->value);
            $entity->value = 8;
            $this->assertTrue($entity->update());
        });
        $this->assertTrue($entity->insert());
        $this->assertSame(8, ConvertedKeyMysqlEntity::findRecord('2')->value);
        $entity->id = new ConvertedKey('2');
        $this->assertTrue($entity->delete());
        $this->assertNull(ConvertedKeyMysqlEntity::findRecord('2'));
        $this->assertSame(0, $this->value());
    }

    public static function stringPrimaryKeyModes(): array
    {
        $cases = [];
        foreach (['uuid'=>'550e8400-e29b-41d4-a716-446655440000', 'leading-zero'=>'01', 'zero'=>'0', 'numeric'=>'123'] as $name => $key) {
            foreach (['global','entity'] as $mode) { $cases[$name . '-' . $mode] = [$key,$mode]; }
        }
        return $cases;
    }

    #[DataProvider('stringPrimaryKeyModes')]
    public function testMysqlNonAutoStringKeySurvivesInsertObserverUpdatesAndDeletion(string $key, string $mode): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(64) NOT NULL");
        $entity = new TextKeyMysqlEntity(['id'=>$key,'value'=>7]);
        $inserts = $updates = 0;
        $observer = function (QueryResult $result) use ($entity,$key,&$inserts,&$updates): void {
            $sql = $result->getQueryBuilder()?->getLastPrepareQuery() ?? '';
            if (str_starts_with($sql,'UPDATE')) { $updates++; return; }
            if (!str_starts_with($sql,'INSERT')) { return; }
            $inserts++;
            // 非自增 VARCHAR 主键没有生成 ID，公开回调中仍应保留调用方传入的字符串。
            $this->assertSame(0, $result->getInsertId());
            $this->assertSame(1, $result->getAffectedRows());
            $this->assertSame($key, $entity->id);
            $entity->value = 8;
            $this->assertTrue($entity->update());
        };
        if ($mode === 'global') { $this->db->setOnQuery($observer); }
        else { $entity->setOnQuery($observer); }

        $this->assertTrue($entity->insert());
        $this->assertSame(1, $inserts);
        $this->assertSame($key, $entity->id);
        $stored = TextKeyMysqlEntity::findRecord($key);
        $this->assertSame($key, $stored->id);
        $this->assertSame(8, $stored->value);
        // 插入和回调内更新已接受比较基准，未修改时不应重复发送 UPDATE。
        $this->assertTrue($entity->update());
        $this->assertSame(1, $updates);
        $entity->value = 9;
        $this->assertTrue($entity->update());
        $this->assertSame(2, $updates);
        $this->assertSame(9, TextKeyMysqlEntity::findRecord($key)->value);
        $this->assertTrue($entity->delete());
        $this->assertNull(TextKeyMysqlEntity::findRecord($key));
        $this->assertSame(0, $this->value(), 'The existing string key 1 must remain unchanged, including when the inserted key is 01.');
    }

    public static function scopedRelationModes(): array { return [[false],[true]]; }

    #[DataProvider('scopedRelationModes')]
    public function testMysqlPreloadUsesQueryLimitScopeAndKeepsCacheHits(bool $arrays): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` ADD COLUMN active INT NOT NULL DEFAULT 1");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,1,1),(3,1,1),(4,2,1),(5,1,0)");
        $owner = new ScopedMysqlEntity();
        $owner->queryLimit()->where('id',2,'<=')->orderBy('id','ASC')->withRelations('children');
        if ($arrays) { $owner->queryLimit()->returnAsArray(); }
        $queries = [];
        $this->db->setOnQuery(static function (QueryResult $result) use (&$queries): void { $queries[] = $result; });
        $parents = $owner->all()->list();
        $this->assertCount(2, $parents);
        foreach ($parents as $index => $parent) {
            $expected = $index === 0 ? [2,3] : [4];
            if ($arrays) { $this->assertSame($expected, array_column($parent['children'],'id')); }
            else {
                for ($read = 0; $read < 2; $read++) {
                    $this->assertSame($expected, array_map(static fn($child) => $child->id, $parent->children()->list()));
                }
            }
        }
        $this->assertCount(2, $queries);
    }

    public function testMysqlChunkContinuesAfterTheObserverRemovesAFullPage(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,1),(3,2),(4,3),(5,4)");
        $entity = new TransactionEntity();
        $entity->queryLimit()->orderBy('id','ASC');
        $pages = 0;
        $entity->setOnQuery(static function (QueryResult $result) use (&$pages): void {
            $pages++;
            if ($pages === 1) { $result->setResult([]); }
        });
        $seen = [];
        $entity->chunk(static function (TransactionEntity $row) use (&$seen): void { $seen[] = $row->id; },2);
        $this->assertSame([3,4,5], $seen);
        $this->assertSame(3, $pages);
    }

    public static function cursorTotalModes(): array { return [[false], [true]]; }

    #[DataProvider('cursorTotalModes')]
    public function testMysqlCursorQuotesReservedFieldsAndKeepsOrBoundariesLocksAndTotals(bool $withTotal): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` ADD COLUMN `order` INT NOT NULL DEFAULT 10");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,2,20),(3,3,30),(4,4,40),(5,5,50)");
        $pages = [];
        $this->db->setOnQuery(static function (QueryResult $result) use (&$pages): void {
            $sql = $result->getQueryBuilder()?->getLastPrepareQuery();
            if ($sql !== null && str_starts_with($sql, 'SELECT')) { $pages[] = $result; }
        });
        $seen = $entityEvents = [];
        $this->db->transaction(function () use ($withTotal, &$seen, &$entityEvents): void {
            $entity = new CursorMysqlEntity();
            $entity->setOnQuery(static function (QueryResult $result) use (&$entityEvents): void {
                $entityEvents[] = $result->getRawSql();
            });
            $entity->queryLimit()->where('value',0)->orWhere('id',4,'>=')->orderBy('id','DESC')
                ->page(99, $withTotal, 1)->selectForUpdate()->returnAsArray()->hideFields('order');
            $this->assertSame(3, $entity->chunkById(function (array $row) use (&$seen): void {
                $this->assertArrayNotHasKey('order', $row);
                $seen[] = $row['id'];
                if ($row['id'] === 4) { $this->assertSame(1, TransactionEntity::fastDelete(4)); }
            }, 2, 'order'));
        });
        $this->assertSame([1,4,5], $seen);
        $this->assertCount(2, $pages);
        $this->assertSame($withTotal ? [null,'SELECT FOUND_ROWS() as count',null,'SELECT FOUND_ROWS() as count'] : [null,null], $entityEvents);
        foreach ($pages as $page) {
            $sql = $page->getQueryBuilder()->getLastPrepareQuery();
            $this->assertStringContainsString('ORDER BY `order` ASC', $sql);
            $this->assertStringContainsString('FOR UPDATE', $sql);
            $this->assertSame($withTotal, $page->getTotalResult() !== null);
        }
        if ($withTotal) { $this->assertSame([3,1], array_map(static fn(QueryResult $page) => $page->getTotalCount(), $pages)); }
    }

    public function testMysqlBulkWritesUseDriverValidationAndIndependentBatchColumns(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY value INT NOT NULL DEFAULT 0");
        $this->assertSame(3, TransactionEntity::fastInsertAll([
            ['id'=>2,'value'=>20], ['id'=>3,'value'=>30], ['id'=>4],
        ], batchSize: 2));
        $this->assertSame(0, TransactionEntity::findRecord(4)->value);
        $this->assertSame(5, TransactionEntity::fastUpsert([
            ['id'=>2,'value'=>21], ['id'=>3,'value'=>31], ['id'=>5,'value'=>50],
        ], ['value'], batchSize: 2));
        $rows = $this->observer->query("SELECT id,value FROM `{$this->table}` ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $this->assertSame([0,21,31,0,50], array_map('intval', array_column($rows, 'value')));
    }

    public function testDeclaredRelationsShareProtectedConfigurationAcrossLazyAndPreloadedQueries(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,1),(3,1),(4,2)");
        DeclaredMysqlEntity::$getterCalls = 0;
        $owner = new DeclaredMysqlEntity();
        $owner->queryLimit()->where('id', 2, '<=')->orderBy('id', 'ASC')->withRelations('children');
        $parents = $owner->all()->list();
        $this->assertSame(0, DeclaredMysqlEntity::$getterCalls);
        $this->assertSame([3], array_map(static fn($child) => $child->id, $parents[0]->children()->list()));
        $this->assertSame([4], array_map(static fn($child) => $child->id, $parents[1]->children()->list()));
        $lazy = new DeclaredMysqlEntity(['id'=>1,'value'=>0]);
        $this->assertSame([3], array_map(static fn($child) => $child->id, $lazy->children()->list()));
        $owner->queryLimit()->where('id',2,'<=')->orderBy('id','ASC')->returnAsArray()->withRelations('children');
        $rows = $owner->all()->list();
        $this->assertSame([3], array_column($rows[0]['children'],'id'));
        $this->assertSame([4], array_column($rows[1]['children'],'id'));
        $this->assertSame(3, DeclaredMysqlEntity::$getterCalls);
    }

    public function testConditionShortcutsPreserveTextKeysAndShareOperators(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(20) NOT NULL");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES ('01',10),('02',20),('a',30),('b',40),('a,b',50)");
        $rows = TextKeyMysqlEntity::findAll('01,02,a', returnAsArray: true);
        $ids = array_column($rows, 'id'); sort($ids);
        $this->assertSame(['01', '02', 'a'], $ids);
        $this->assertSame('a,b', TextKeyMysqlEntity::findRecord('a,b')->id);
        $this->assertSame(2, TextKeyMysqlEntity::fastUpdate(['id' => [['01', 'a'], 'IN'], 'value' => [5, '>']], ['value' => 99]));
        $this->assertSame(99, TextKeyMysqlEntity::findRecord('01')->value);
        $this->assertSame(0, TextKeyMysqlEntity::findRecord('1')->value);
        $this->assertSame(20, TextKeyMysqlEntity::findRecord('02')->value);
        $this->assertSame(2, TextKeyMysqlEntity::fastDelete('01,a'));
        $this->assertSame([], TextKeyMysqlEntity::findAll('01,a', returnAsArray: true));
        $this->assertSame(50, TextKeyMysqlEntity::findRecord('a,b')->value);
        $this->assertSame(2, TextKeyMysqlEntity::fastUpdate('02,b', ['value' => 77]));
        $this->assertSame(77, TextKeyMysqlEntity::findRecord('02')->value);
        $this->assertSame(1, TextKeyMysqlEntity::fastDelete(['value' => [75, '>'], 'id' => '02']));
        $this->assertSame('b', TextKeyMysqlEntity::findRecord(['value' => [70, '>']])->id);
    }

    public function testFunctionNameKeysAndArrayCallbacksKeepMysqlWritesScoped(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(20) NOT NULL");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES ('is_object',10),('trim',20)");
        $this->assertSame('trim', TextKeyMysqlEntity::findRecord('trim')->id);
        $this->assertSame(1, TextKeyMysqlEntity::fastUpdate('is_object', ['value' => 30]));
        $this->assertSame(0, TextKeyMysqlEntity::fastUpdate('is_null', ['value' => 99]));
        $this->assertSame(0, TextKeyMysqlEntity::fastDelete('is_null'));
        $this->assertSame(0, TextKeyMysqlEntity::findRecord('1')->value);
        $this->assertSame(20, TextKeyMysqlEntity::findRecord('trim')->value);
        $filter = new class {
            public function onlyOriginal(\EasySwoole\Mysqli\QueryBuilder $query): void { $query->where('id', '1'); }
        };
        $this->assertSame(1, TextKeyMysqlEntity::fastUpdate([$filter, 'onlyOriginal'], ['value' => 7]));
        $this->assertSame(7, TextKeyMysqlEntity::findRecord('1')->value);
        $this->assertSame(30, TextKeyMysqlEntity::findRecord('is_object')->value);
        $this->assertSame(1, TextKeyMysqlEntity::fastDelete('is_object'));
        $this->assertCount(2, TextKeyMysqlEntity::findAll());
    }

    public function testMysqlAggregatesQuoteReservedColumnsAndQualifiedAliases(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` ADD COLUMN `order` INT NULL");
        $this->observer->query("UPDATE `{$this->table}` SET `order` = 10 WHERE id = 1");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,5,20),(3,7,NULL)");
        $entity = new TransactionEntity();
        $qualified = $this->table . '.order';
        $this->assertSame(2, $entity->count('order'));
        $this->assertSame(2, $entity->count($qualified));
        $this->assertSame(3, $entity->count('1'));
        $this->assertSame(0, $entity->count('NULL'));
        $this->assertSame(2, $entity->count('DISTINCT `order`'));
        $this->assertSame(1, $entity->count('CASE WHEN value > 5 THEN id END'));
        $entity->queryLimit()->fields(['order', $qualified]);
        $this->assertSame(['order' => 2, $qualified => 2], $entity->count());
        foreach (['sum' => 30.0, 'avg' => 15.0, 'min' => 10.0, 'max' => 20.0] as $method => $expected) {
            $this->assertSame($expected, $entity->$method('order'));
            $this->assertSame($expected, $entity->$method($qualified));
            $this->assertSame(['order' => $expected, $qualified => $expected], $entity->$method(['order', $qualified]));
        }
        $entity->queryLimit()->join($this->table . ' AS b', $this->table . '.id = b.id', 'INNER')->where('b.id', 2);
        $this->assertSame(20.0, $entity->sum('b.order'));
    }

    public function testMysqlPaginationKeepsItsTotalWhenObserverReusesTheBuilder(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (2,1),(3,2)");
        $entity = new TransactionEntity();
        $entity->queryLimit()->limit(1, true);
        $builder = $entity->queryLimit()->__getQueryBuilder();
        $entered = false;
        $this->db->setOnQuery(function (QueryResult $result) use ($builder, &$entered): void {
            if ($entered) { return; }
            $entered = true;
            $this->assertSame(3, $result->getTotalCount());
            $this->db->query($builder->raw('SELECT 42 AS probe'));
        });
        $page = $entity->all();
        $this->assertSame(3, $page->totalCount());
        $this->assertCount(1, $page);
    }

    public function testZeroPrimaryKeysSupportMysqlEntityAndFastDeletion(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES (0,5)");
        $integer = TransactionEntity::findRecord(0);
        $this->assertSame(0, $integer->id);
        $integer->value = 6;
        $this->assertTrue($integer->update());
        $this->assertSame(6, TransactionEntity::findRecord(0)->value);
        $this->assertSame(0, $this->value());
        $this->assertTrue($integer->delete());
        $this->assertNull(TransactionEntity::findRecord(0));

        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(20) NOT NULL");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES ('0',7)");
        $string = TextKeyMysqlEntity::findRecord('0');
        $this->assertSame('0', $string->id);
        $string->value = 8;
        $this->assertTrue($string->update());
        $this->assertSame(8, TextKeyMysqlEntity::findRecord('0')->value);
        $this->assertTrue($string->delete());
        foreach ([0, '0'] as $key) {
            $this->observer->query("INSERT INTO `{$this->table}` VALUES ('0',9)");
            $this->assertSame(1, TextKeyMysqlEntity::fastDelete($key));
            $this->assertNull(TextKeyMysqlEntity::findRecord('0'));
        }
        $this->assertSame(0, $this->value());
    }

    public function testConvertedPrimaryKeyUsesTheMysqlValueAndRejectsActualChanges(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(20) NOT NULL");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES ('0',5),('uuid-a',10),('uuid-b',20)");
        $entity = ConvertedKeyMysqlEntity::findRecord('uuid-a');
        $entity->id = new ConvertedKey('uuid-a');
        $entity->value = 11;
        $this->assertTrue($entity->update());
        $this->assertSame(11, TextKeyMysqlEntity::findRecord('uuid-a')->value);
        foreach (['update', 'delete'] as $operation) {
            $entity->id->value = 'uuid-b';
            $entity->value = 12;
            try { $entity->$operation(); $this->fail('Changed primary key must be rejected'); }
            catch (\EasySwoole\FastDb\Exception\RuntimeError $error) {
                $this->assertSame('Cannot change entity primary key: id', $error->getMessage());
            }
            $this->assertSame(11, TextKeyMysqlEntity::findRecord('uuid-a')->value);
            $this->assertSame(20, TextKeyMysqlEntity::findRecord('uuid-b')->value);
            $entity->id = new ConvertedKey('uuid-a');
        }
        $this->assertTrue($entity->delete());
        $this->assertNull(TextKeyMysqlEntity::findRecord('uuid-a'));
        $zero = ConvertedKeyMysqlEntity::findRecord('0');
        $zero->value = 6;
        $this->assertTrue($zero->update());
        $this->assertSame(6, TextKeyMysqlEntity::findRecord('0')->value);
        $this->assertTrue($zero->delete());
    }

    public function testConvertedKeysSupportMysqlLazyAndPreloadedRelations(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` MODIFY id VARCHAR(20) NOT NULL");
        $this->observer->query("INSERT INTO `{$this->table}` VALUES ('uuid-a',10),('uuid-b',20)");
        $entity = ConvertedKeyMysqlEntity::findRecord('uuid-a');
        $this->assertSame('uuid-a', $entity->record()->id);
        $entity->id = new ConvertedKey('uuid-a');
        $this->assertSame('uuid-a', $entity->record()->id);
        $this->assertSame('uuid-a', $entity->records()->first()->id);
        foreach ([false, true] as $asArray) {
            $queries = 0;
            $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
            $owner = new ConvertedKeyMysqlEntity();
            $owner->queryLimit()->fields(null, $asArray)->orderBy('id', 'ASC')->withRelations(['record', 'records']);
            $parents = $owner->all()->list();
            $this->assertCount(3, $parents);
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
            $this->assertSame(3, $queries);
        }
    }

    public function testMysqlJsonEncodingFailureDoesNotReplaceStoredText(): void
    {
        $this->observer->query("ALTER TABLE `{$this->table}` ADD COLUMN items TEXT NULL");
        $this->observer->query("UPDATE `{$this->table}` SET items='[1]' WHERE id=1");
        $entity = EncodingMysqlEntity::findRecord(1);
        $entity->items->append(NAN);
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        try { $entity->update(); $this->fail('Invalid JSON must fail before SQL'); }
        catch (\JsonException) {}
        $this->assertSame(0, $queries);
        $this->assertSame('[1]', $this->observer->query("SELECT items FROM `{$this->table}` WHERE id=1")->fetch_assoc()['items']);
        $this->assertTrue(is_nan($entity->items->toArray()[1]));
        $entity->setData(['items' => '[2]']);
        EncodingMysqlList::$encodes = 0;
        $this->assertTrue($entity->update());
        $this->assertSame(1, EncodingMysqlList::$encodes);
        $this->assertSame(1, $queries);
        $this->assertSame('[2]', $this->observer->query("SELECT items FROM `{$this->table}` WHERE id=1")->fetch_assoc()['items']);
    }

    public function testDefaultRecycleRollsBackTheSessionAndClearsDiagnostics(): void
    {
        $this->db->isEnableQueryStack(true);
        $this->db->begin();
        $this->write(1);
        $client = $this->db->currentConnection();
        $this->assertNotNull($this->db->getQueryStack());
        $this->db->recycleContext();
        $this->assertNull($this->db->currentConnection());
        $this->assertNull($this->db->getQueryStack());
        $this->assertFalse($client->isInTransaction());
        $this->assertSame(0, $this->value());
        $this->assertTrue($this->db->begin());
        $this->write(2);
        $this->assertTrue($this->db->commit());
        $this->assertSame(2, $this->value());
    }

    public function testInsertObserverCanUpdateTheInsertedEntityWithoutLosingItsBaseline(): void
    {
        $this->db->rawQuery("ALTER TABLE `{$this->table}` MODIFY id INT NOT NULL AUTO_INCREMENT");
        $entity = new TransactionEntity(['value' => 3]);
        $nested = false;
        $this->db->setOnQuery(function () use ($entity, &$nested): void {
            if ($nested) { return; }
            $nested = true;
            $this->assertSame(2, $entity->id);
            $entity->value = 9;
            $this->assertTrue($entity->update());
        });
        $this->assertTrue($entity->insert());
        $this->assertSame(9, $entity->value);
        $this->assertSame(9, (int) $this->observer->query("SELECT value FROM `{$this->table}` WHERE id=2")->fetch_assoc()['value']);
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
    }

    public function testInsertIdSnapshotSurvivesSqlInGlobalLogCallback(): void
    {
        $this->db->rawQuery("ALTER TABLE `{$this->table}` MODIFY id INT NOT NULL AUTO_INCREMENT");
        $nested = false;
        $this->db->setOnQuery(function () use (&$nested): void {
            if (!$nested) {
                $nested = true;
                $this->db->rawQuery("INSERT INTO `{$this->table}` (value) VALUES (9)");
            }
        });
        $entity = new TransactionEntity(['value' => 7]);
        $this->assertTrue($entity->insert());
        $this->assertSame(2, $entity->id);
        $this->assertSame(3, (int) $this->db->currentConnection()->getLastInsertId());
        $this->assertSame(7, (int) $this->observer->query("SELECT value FROM `{$this->table}` WHERE id=2")->fetch_assoc()['value']);
    }

    public function testUpdateResultAndBaselineStayOnOriginatingConnection(): void
    {
        $this->db->addDb(new Config(MYSQL_CONFIG), 'other');
        $this->db->begin();
        $origin = $this->db->currentConnection();
        $nested = false;
        $this->db->setOnQuery(function () use (&$nested): void {
            if (!$nested) {
                $nested = true;
                // 覆盖原连接的查询元数据，再切换到另一连接。
                $this->db->rawQuery("UPDATE `{$this->table}` SET value=0 WHERE id=-1");
                $this->db->selectConnection('other');
                $this->db->rawQuery('SELECT 1');
            }
        });
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $entity->value = 7;
        $this->assertTrue($entity->update());
        $this->assertSame('other', $this->db->selectConnection());
        $this->assertNotSame($origin, $this->db->currentConnection());
        $this->db->setOnQuery(static function (): void {});
        $this->db->selectConnection('default');
        $this->assertSame($origin, $this->db->currentConnection());
        $this->db->rollback();
        $this->assertSame(0, $this->value());
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
        $this->assertSame(7, $entity->value);
        $this->assertSame(0, $this->value());
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 7;
        $this->assertTrue($reloaded->update());
        $this->assertSame(7, $this->value());
    }

    public function testCoroutineExitReturnsTheDatabaseSessionAfterBusinessError(): void
    {
        $db = (new FastDb())->addDb(new Config(MYSQL_CONFIG));
        $weak = $message = null;
        $finished = new \Swoole\Coroutine\Channel(1);
        try {
            \Swoole\Coroutine::create(function () use ($db, &$weak, &$message, $finished): void {
                // 退出通知最后执行，观察者读到结果时会话已归还并回滚未提交写入。
                \Swoole\Coroutine::defer(static fn() => $finished->push(true));
                try {
                    $db->begin();
                    $result = $db->rawQuery("UPDATE `{$this->table}` SET value=7 WHERE id=1");
                    $weak = \WeakReference::create($result);
                    unset($result);
                    throw new \RuntimeException('business failure');
                } catch (\Throwable $error) { $message = $error->getMessage(); }
            });
            $this->assertTrue($finished->pop(5));
            $this->assertSame('business failure', $message);
            $this->assertSame(0, $this->value());
            $this->assertNull($weak->get());
            $this->assertNull($db->currentConnection());
            $this->assertNull($db->getQueryStack());
            $db->begin();
            $db->rawQuery("UPDATE `{$this->table}` SET value=9 WHERE id=1");
            $db->rollback();
            $this->assertSame(0, $this->value());
        } finally { $db->recycleContext(); $db->reset(); }
    }

    public function testChangedPrimaryKeyCannotUpdateAnotherRow(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES(2, 5)");
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $entity->id = 2;
        $entity->value = 9;
        try { $entity->update(); $this->fail('Expected primary key guard'); }
        catch (\EasySwoole\FastDb\Exception\RuntimeError $error) {
            $this->assertStringContainsString('primary key', $error->getMessage());
        }
        $this->assertSame(0, $this->value());
        $this->assertSame(5, (int) $this->observer->query("SELECT value FROM `{$this->table}` WHERE id=2")->fetch_assoc()['value']);
    }

    public function testRollbackInsideLoggerDoesNotChangeTheSuccessfulUpdateResultOrBaseline(): void
    {
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $this->db->begin(); $entity->value = 7; $once = false;
        $this->db->setOnQuery(function ($result) use (&$once): void {
            if (!$once && str_starts_with($result->getQueryBuilder()?->getLastPrepareQuery() ?? '', 'UPDATE')) {
                $once = true; $this->db->rollback();
            }
        });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $this->value());
        $this->assertSame(7, $entity->value);
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
        $this->assertSame(0, $this->value());
        // 回滚后的数据库状态由业务重新加载，再应用待写入值。
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 7;
        $this->assertTrue($reloaded->update());
        $this->assertSame(7, $this->value());
    }

    public function testPaginationCountSurvivesLoggingQueries(): void
    {
        $this->observer->query("INSERT INTO `{$this->table}` VALUES(2,0),(3,0)");
        $once = false;
        $this->db->setOnQuery(function ($result) use (&$once): void {
            if (!$once && in_array('SQL_CALC_FOUND_ROWS', $result->getQueryBuilder()?->getLastQueryOptions() ?? [], true)) {
                $once = true; $this->db->rawQuery('SELECT 1');
            }
        });
        $entity = new TransactionEntity(); $calls = 0;
        $entity->setOnQuery(static function () use (&$calls): void { $calls++; });
        $entity->queryLimit()->page(1, true, 1);
        $list = $entity->all();
        $this->assertCount(1, $list);
        $this->assertSame(3, $list->totalCount());
        $this->assertSame(2, $calls);
    }

    public function testRawTransactionIsRolledBackWhenTheSessionIsReturned(): void
    {
        $this->db->rawQuery('START TRANSACTION');
        $this->write(7);
        $client = $this->db->currentConnection();
        $this->assertTrue($client->isInTransaction());
        $this->db->recycleContext();
        $this->assertFalse($client->isInTransaction());
        $this->assertNull($this->db->currentConnection());
        $this->db->begin(); $this->db->rollback();
        $this->assertSame(0, $this->value());
    }

    public function testCommentedRawRollbackKeepsTheEntityBaselineUntilBusinessReloads(): void
    {
        $this->db->begin();
        $entity = new TransactionEntity(['id' => 1, 'value' => 0]);
        $entity->value = 7; $this->assertTrue($entity->update());
        $this->db->rawQuery('/* trace */ ROLLBACK AND CHAIN');
        $this->assertTrue($this->db->isInTransaction());
        $this->assertTrue($entity->update());
        $this->db->commit();
        $this->assertSame(0, $this->value());
        $this->assertSame(7, $entity->value);
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 7;
        $this->assertTrue($reloaded->update());
        $this->assertSame(7, $this->value());
    }

    public function testInsertIgnoresSerializationHiddenFields(): void
    {
        $entity = new TransactionEntity(['id' => 2, 'value' => 7]);
        $entity->queryLimit()->hideFields(['value']);
        $this->assertTrue($entity->insert());
        $this->assertArrayNotHasKey('value', $entity->toArray());
        $this->assertSame(7, (int) $this->observer->query("SELECT value FROM `{$this->table}` WHERE id=2")->fetch_assoc()['value']);
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
    public function testRollbackRequiresBusinessReloadBeforeRetryingSuccessfulWrites(string $mode): void
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
            if($mode === 'recycleContext'){
                $this->db->recycleContext();
            }else{
                $this->db->{$mode}();
            }
        }
        $this->assertSame(0, $this->value());
        $this->assertSame(2, $entity->value);
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
        $this->assertSame(0, $this->value());
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 2;
        $this->assertTrue($reloaded->update());
        $this->assertSame(2, $this->value());
    }

    public function testCommitAndRollbackLeaveTheLatestSuccessfulComparisonBaseline(): void
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
        $this->assertFalse($entity->hasChange());
        $this->assertSame([], $entity->getChanges());
        $queries = 0;
        $this->db->setOnQuery(static function () use (&$queries): void { $queries++; });
        $this->assertSame(2, $entity->value);
        $this->assertTrue($entity->update());
        $this->assertSame(0, $queries);
        $this->assertSame(1, $this->value());
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 2;
        $this->assertTrue($reloaded->hasChange('value'));
        $this->assertSame(['value'=>2], $reloaded->getChanges());
        $this->assertTrue($reloaded->update());
        $this->assertSame(2, $this->value());
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
            $this->db->rawQuery('SELECT 1'); // 开始计时前先获取并建立连接。
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
        $this->assertFalse($client->isInTransaction());
        $this->assertTrue($this->db->begin());
        $this->write(3); // 等待服务端释放已断开会话持有的行锁。
        $this->assertTrue($this->db->rollback());
        $this->assertSame(0, $this->value());
        $this->assertTrue($entity->update());
        $this->assertSame(0, $this->value());
        $reloaded = TransactionEntity::findRecord(1);
        $reloaded->value = 1;
        $this->assertTrue($reloaded->update());
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
            $this->assertFalse($client->isInTransaction());
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
            // 通过锁竞争等待服务端处理关闭的会话。
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
                // 命令转发后才延迟响应，因此服务端可能已经成功执行 COMMIT。
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

class DeclaredMysqlEntity extends AbstractEntity
{
    public static int $getterCalls = 0;
    #[Property(isPrimaryKey:true)] public int $id;
    #[Property] public int $value;
    public function tableName(): string { return TransactionEntity::$table; }
    #[\EasySwoole\FastDb\Attributes\Relate(self::class,'value',type:'many',configure:'configureChildren')]
    public function children(): \EasySwoole\FastDb\Beans\ListResult
    {
        self::$getterCalls++;
        return $this->relateMany();
    }
    protected function configureChildren(\EasySwoole\Mysqli\QueryBuilder $query): void
    {
        $query->where('id', $this->id === 1 ? 3 : 4, '>=')->orderBy('id','ASC');
    }
}

class TextKeyMysqlEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public string $id;
    #[Property] public int $value;
    public function tableName(): string { return TransactionEntity::$table; }
}

class ConvertedKeyMysqlEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true, convertObject: ConvertedKey::class)] public ?ConvertedKey $id;
    #[Property] public int $value;
    public function tableName(): string { return TransactionEntity::$table; }
    #[Relate(TextKeyMysqlEntity::class, 'id', type: 'one', cacheResult: true)]
    public function record() { return $this->relateOne(); }
    #[Relate(TextKeyMysqlEntity::class, 'id', type: 'many', cacheResult: true)]
    public function records() { return $this->relateMany(); }
}

class EncodingMysqlEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property(convertObject: EncodingMysqlList::class)] public ?EncodingMysqlList $items;
    public function tableName(): string { return TransactionEntity::$table; }
}

class EncodingMysqlList extends ConvertList
{
    public static int $encodes = 0;
    public function toValue(): string { self::$encodes++; return parent::toValue(); }
}

class CursorMysqlEntity extends DeclaredMysqlEntity
{
    #[Property] public int $order;
}

class ScopedMysqlEntity extends AbstractEntity
{
    private ?\WeakReference $scopedQuery = null;
    #[Property(isPrimaryKey:true)] public int $id;
    #[Property] public int $value;
    public function tableName(): string { return TransactionEntity::$table; }
    public function queryLimit(): \EasySwoole\FastDb\Beans\Query
    {
        $query = parent::queryLimit();
        if ($this->scopedQuery?->get() !== $query) {
            $this->scopedQuery = \WeakReference::create($query);
            $query->where('active',1);
        }
        return $query;
    }
    #[Relate(self::class,'value',type:'many',configure:'configureChildren')]
    public function children() { return $this->relateMany(); }
    protected function configureChildren(\EasySwoole\Mysqli\QueryBuilder $query): void { $query->orderBy('id','ASC'); }
}
