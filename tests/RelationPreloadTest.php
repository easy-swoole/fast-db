<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class RelationPreloadTest extends TestCase
{
    private ?\ReflectionProperty $singleton = null;
    private mixed $originalInstance = null;
    private FastDb $db;
    private PreloadFixtureConnection $connection;
    private array $events = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) { $this->markTestSkipped('SQLite executes relation queries without MySQL writes.'); }
        $database = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $database->exec('CREATE TABLE preload_parents (id INTEGER PRIMARY KEY, group_code TEXT, name TEXT)');
        $database->exec('CREATE TABLE preload_children (id INTEGER PRIMARY KEY, parent_id INTEGER, group_code TEXT COLLATE NOCASE, name TEXT, payload TEXT)');
        $database->exec('CREATE TABLE preload_profiles (id INTEGER PRIMARY KEY, parent_id INTEGER, name TEXT)');
        $database->exec("INSERT INTO preload_parents VALUES (1,'a','first'),(2,'a','second'),(3,'b','third')");
        $database->exec("INSERT INTO preload_children VALUES (11,1,'a','child-11','[11]'),(12,1,'a','child-12','[12]'),(13,2,'a','child-13','[13]')");
        $database->exec("INSERT INTO preload_profiles VALUES (21,1,'profile-1'),(23,3,'profile-3')");
        $this->connection = new PreloadFixtureConnection($database);
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

    private function parents(array|string $relations): array
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->withRelations($relations);
        return $owner->all()->list();
    }

    private function childIds(PreloadFixtureParent $parent): array
    {
        return array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parent->children()->list());
    }

    public static function scopedOutputModes(): array
    {
        return [[PreloadScopedParent::class,false], [PreloadScopedParent::class,true],
            [PreloadInheritedScopedParent::class,false], [PreloadInheritedScopedParent::class,true],
            [PreloadAppendingScopeParent::class,false], [PreloadAppendingScopeParent::class,true]];
    }

    #[DataProvider('scopedOutputModes')]
    public function testPreloadKeepsQueryLimitOverridesAndHitsBothRelationCaches(string $class, bool $arrays): void
    {
        foreach (['preload_parents','preload_children','preload_profiles'] as $table) {
            $this->connection->database->exec('ALTER TABLE ' . $table . ' ADD COLUMN active INTEGER NOT NULL DEFAULT 1');
        }
        $this->connection->database->exec("INSERT INTO preload_children VALUES (99,1,'a','excluded','[]',0)");
        $this->connection->database->exec("INSERT INTO preload_profiles VALUES (98,1,'excluded',0)");
        $lazy = new $class(['id'=>1]);
        $this->assertSame([11,12], array_map(static fn($child) => $child->id, $lazy->children()->list()));
        $this->assertSame(21, $lazy->profile()->id);
        $this->connection->queries = $this->events = [];

        $owner = new $class();
        $owner->queryLimit()->orderBy('id','ASC')->withRelations(['children','profile']);
        if ($arrays) { $owner->queryLimit()->returnAsArray(); }
        $parents = $owner->all()->list();
        $this->assertCount(3, $parents);
        $expectedChildren = [[11,12],[13],[]];
        $expectedProfiles = [21,null,23];
        foreach ($parents as $index => $parent) {
            if ($arrays) {
                $this->assertSame($expectedChildren[$index], array_column($parent['children'],'id'));
                $this->assertSame($expectedProfiles[$index], $parent['profile']['id'] ?? null);
            } else {
                // 连续读取仍应命中预加载，不因默认条件丢失或 reset 再次查库。
                for ($read = 0; $read < 2; $read++) {
                    $this->assertSame($expectedChildren[$index], array_map(static fn($child) => $child->id, $parent->children()->list()));
                    $this->assertSame($expectedProfiles[$index], $parent->profile()?->id);
                }
            }
        }
        $this->assertCount(3, $this->connection->queries);
        $this->assertCount(3, $this->events);
    }

    public function testOneRelationClearsItsConditionsBeforeHydrationReentersTheParent(): void
    {
        $parent = new ReentrantProfileParent(['id'=>1]);
        $parent->queryLimit()->where('name','profile-1');
        ReentrantProfile::$parent = $parent;
        ReentrantProfile::$parentCount = null;
        try {
            $this->assertSame(21, $parent->reentrantProfile()->id);
            $this->assertSame(3, ReentrantProfile::$parentCount);
            $this->assertCount(2, $this->connection->queries);
        } finally { ReentrantProfile::$parent = null; }
    }

    public function testManyRelationClearsItsSelectionBeforeHydrationReentersTheParent(): void
    {
        $parent = new ReentrantProfileParent(['id' => 1]);
        $parent->queryLimit()->fields(['id', 'parent_id', 'name'])->where('name', 'profile-1');
        ReentrantProfile::$parent = $parent;
        ReentrantProfile::$parentCount = null;
        try {
            $this->assertSame(21, $parent->reentrantProfiles()->first()->id);
            $this->assertSame(3, ReentrantProfile::$parentCount);
            $this->assertCount(2, $this->connection->queries);
        } finally { ReentrantProfile::$parent = null; }
    }

    public function testQueriesWithoutPreloadingDoNotExecuteRelationSql(): void
    {
        $parents = (new PreloadFixtureParent())->all()->list();
        $this->assertCount(3, $parents);
        $this->assertCount(1, $this->connection->queries);
        $this->assertCount(1, $this->events);
    }

    public function testBatchLoadingRemovesThePerParentQueriesAndKeepsExistingRelationCalls(): void
    {
        $parents = $this->parents(['children', 'profile']);
        $this->assertCount(3, $this->connection->queries);
        $this->assertCount(3, $this->events);
        $this->assertSame([11, 12], $this->childIds($parents[0]));
        $this->assertSame([13], $this->childIds($parents[1]));
        $this->assertSame([], $this->childIds($parents[2]));
        $this->assertSame('profile-1', $parents[0]->profile()->name);
        $this->assertNull($parents[1]->profile());
        $this->assertSame('profile-3', $parents[2]->profile()->name);
        $this->assertCount(3, $this->connection->queries);
        $this->assertCount(3, $this->events);
    }

    public function testPrefetchedChildrenHaveConvertedFieldsAndAnAcceptedDatabaseBaseline(): void
    {
        $parents = $this->parents('children');
        $child = $parents[0]->children()->first();
        $this->assertInstanceOf(ConvertList::class, $child->payload);
        $this->assertSame([11], $child->payload->toArray());
        $this->assertTrue($child->update());
        $this->assertCount(2, $this->connection->queries);
        $child->name = 'changed';
        $this->assertTrue($child->update());
        $this->assertSame('changed', $this->connection->database->query('SELECT name FROM preload_children WHERE id=11')->fetchColumn());
        $this->assertCount(3, $this->connection->queries);
        $this->assertStringNotContainsString('`payload` =', $this->connection->queries[2]['sql']);
    }

    public function testEmptyParentResultDoesNotRunRelationQueries(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->where('id', 999)->withRelations(['children', 'profile']);
        $this->assertSame([], $owner->all()->list());
        $this->assertCount(1, $this->connection->queries);
        $this->assertCount(1, $this->events);
    }

    public function testClearRelationCacheDiscardsPrefetchedRowsAsWell(): void
    {
        $parent = $this->parents('children')[0];
        $this->assertSame([11, 12], $this->childIds($parent));
        $this->connection->database->exec("INSERT INTO preload_children VALUES (14,1,'a','new child','[14]')");
        $parent->clearRelationCache();
        $this->assertSame([11, 12, 14], $this->childIds($parent));
        $this->assertCount(3, $this->connection->queries);
    }

    public function testChangingTheParentRelationKeyCannotReuseTheOldParentGroup(): void
    {
        $parent = $this->parents('children')[0];
        $parent->id = 2;
        $this->assertSame([13], $this->childIds($parent));
        $this->assertCount(3, $this->connection->queries);
    }

    public function testOnePreloadFillsTheTargetConstructedForEachParent(): void
    {
        $this->connection->database->exec("INSERT INTO preload_profiles VALUES (25,1,'a')");
        PreloadFixtureTrackedProfile::$constructed = 0;

        $lazy = (new PreloadFixtureParent(['group_code' => 'a']))->groupProfile();
        $this->assertSame(1, PreloadFixtureTrackedProfile::$constructed);
        $this->assertNull($lazy->constructorData);
        $this->assertSame(25, $lazy->id);
        $this->assertFalse($lazy->hasChange());

        PreloadFixtureTrackedProfile::$constructed = 0;
        $this->connection->queries = [];
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->where('group_code', 'a')->orderBy('id', 'ASC')->withRelations('groupProfile');
        [$firstParent, $secondParent] = $owner->all()->list();
        $this->assertSame(2, PreloadFixtureTrackedProfile::$constructed);

        $first = $firstParent->groupProfile();
        $second = $secondParent->groupProfile();
        $this->assertSame(1, $first->constructionOrder);
        $this->assertSame(2, $second->constructionOrder);
        $this->assertNull($first->constructorData);
        $this->assertNull($second->constructorData);
        $this->assertSame(25, $first->id);
        $this->assertSame(25, $second->id);
        $this->assertFalse($first->hasChange());
        $this->assertFalse($second->hasChange());
        $this->assertNotSame($first, $second);
        $first->name = 'changed locally';
        $this->assertSame('a', $second->name);
        $this->assertCount(2, $this->connection->queries);
    }

    public function testOneRelationHydratesOnTheQueryConnectionAndRestoresTheObserverSelection(): void
    {
        $this->connection->database->exec("INSERT INTO preload_profiles VALUES (25,1,'a')");
        $this->db->setOnQuery(function (QueryResult $result): void {
            if (str_contains($result->getLastPrepareQuery() ?? '', 'preload_profiles')) {
                $this->db->selectConnection('observer');
            }
        });

        $parent = new PreloadFixtureParent(['group_code' => 'a']);
        $this->assertSame('default', $parent->groupProfile()->hydrationConnection);
        $this->assertSame('observer', $this->db->selectConnection());

        $this->db->selectConnection('default');
        $parent = new PreloadFixtureParent();
        $parent->queryLimit()->where('id', 1)->withRelations('groupProfile');
        $row = $parent->all()->first();
        $this->assertSame('default', $this->db->selectConnection());
        $this->assertSame('default', $row->groupProfile()->hydrationConnection);
        $this->assertCount(3, $this->connection->queries);
    }

    public function testManyPreloadStartsEveryChildOnTheQueryConnection(): void
    {
        $parent = new PreloadFixtureParent();
        $parent->queryLimit()->where('id', 1)->withRelations('switchingChildren');
        $row = $parent->all()->first();

        $this->assertSame('default', $this->db->selectConnection());
        $children = $row->switchingChildren()->list();
        $this->assertSame([11, 12], array_column($children, 'id'));
        $this->assertSame(['default', 'default'], array_column($children, 'hydrationConnection'));
        $this->assertCount(2, $this->connection->queries);
    }

    public function testDuplicateOneRelationStillRaisesTheExistingCardinalityError(): void
    {
        $this->connection->database->exec("INSERT INTO preload_profiles VALUES (24,3,'duplicate')");
        try {
            $parents = $this->parents('profile');
            $parents[2]->profile();
            $this->fail('A one relation may not silently choose one duplicate row');
        } catch (RuntimeError $error) {
            $this->assertStringContainsString('more than one', $error->getMessage());
        }
        $this->assertCount(2, $this->connection->queries);
    }

    public function testParentsWithTheSameForeignValueDoNotShareMutableChildInstances(): void
    {
        $parents = $this->parents('groupChildren');
        $first = $parents[0]->groupChildren()->first();
        $second = $parents[1]->groupChildren()->first();
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first, $second);
        $first->name = 'local change';
        $first->payload->append('local item');
        $this->assertSame('child-11', $second->name);
        $this->assertSame([11], $second->payload->toArray());
        $this->assertCount(2, $this->connection->queries);
    }

    public function testNullableRelationKeysUseIsNullInBothPaths(): void
    {
        $this->connection->database->exec("INSERT INTO preload_parents VALUES (4,NULL,'null group')");
        $this->connection->database->exec("INSERT INTO preload_children VALUES (14,4,NULL,'null child','[14]')");
        $lazyParent = new PreloadFixtureParent(['id' => 4, 'group_code' => null, 'name' => 'null group']);
        $this->assertSame([14], array_map(static fn ($child) => $child->id, $lazyParent->groupChildren()->list()));
        $this->assertCount(1, $this->connection->queries);
        $parents = $this->parents('groupChildren');
        $this->assertCount(3, $this->connection->queries);
        $this->assertSame([14], array_map(static fn ($child) => $child->id, $parents[3]->groupChildren()->list()));
        $this->assertSame([], $parents[2]->groupChildren()->list());
        $this->assertCount(3, $this->connection->queries);
    }

    public function testCaseInsensitiveSqlKeysAreNotGroupedByPhpStringEquality(): void
    {
        $this->connection->database->exec("UPDATE preload_parents SET group_code='A' WHERE id=1");
        $parents = $this->parents('groupChildren');
        foreach ([$parents[0], $parents[1]] as $parent) {
            $this->assertSame([11, 12, 13], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parent->groupChildren()->list()));
        }
        $this->assertCount(2, $this->connection->queries);
    }

    public function testNumericSqlAffinityIsNotGroupedByTheTextualParentKey(): void
    {
        $this->connection->database->exec("UPDATE preload_parents SET group_code=CASE id WHEN 1 THEN '01' WHEN 2 THEN '1' ELSE '003' END");
        $parents = $this->parents('numericChildren');
        foreach ([$parents[0], $parents[1]] as $parent) {
            $this->assertSame([11, 12], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parent->numericChildren()->list()));
        }
        $this->assertSame([], $parents[2]->numericChildren()->list());
        $this->assertCount(2, $this->connection->queries);
    }

    public function testGetterWithAdditionalConditionsDoesNotUseAnUnfilteredPrefetch(): void
    {
        $parent = $this->parents('children')[0];
        $parent->queryLimit()->where('id', 12);
        $this->assertSame([12], $this->childIds($parent));
        $this->assertCount(3, $this->connection->queries);
    }

    public function testConfiguredOrConditionsRetainTheirForeignKeyBoundaryInTheBatch(): void
    {
        $parents = $this->parents('filteredChildren');
        $this->assertSame([11], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parents[0]->filteredChildren()->list()));
        $this->assertSame([13], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parents[1]->filteredChildren()->list()));
        $this->assertSame([], $parents[2]->filteredChildren()->list());
        $this->assertCount(2, $this->connection->queries);
    }

    public function testPreloadUsesTheParentQueryConnectionAndRestoresTheObserverSelectedConnection(): void
    {
        $otherDatabase = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $otherDatabase->exec('CREATE TABLE preload_children (id INTEGER, parent_id INTEGER, group_code TEXT, name TEXT, payload TEXT)');
        $otherDatabase->exec("INSERT INTO preload_children VALUES (111,1,'a','other database','[111]')");
        $other = new PreloadFixtureConnection($otherDatabase);
        $other->connectionName = 'other';
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db,
            [Coroutine::getCid() => ['default' => $this->connection, 'other' => $other]]);
        $this->db->setOnQuery(function (QueryResult $result): void {
            $this->events[] = $result;
            if (str_contains($result->getLastPrepareQuery() ?? '', 'preload_parents')) {
                $this->db->selectConnection('other');
            }
        });

        $parents = $this->parents('children');
        $this->assertSame('other', $this->db->selectConnection());
        $this->assertCount(2, $this->connection->queries);
        $this->assertSame([], $other->queries);
        foreach ($this->events as $event) { $this->assertSame('default', $event->getConnectionName()); }
        $this->db->selectConnection('default');
        $this->assertSame([11, 12], $this->childIds($parents[0]));
        $this->assertCount(2, $this->connection->queries);
    }

    #[DataProvider('parentOutputModes')]
    public function testPreloadInitializesParentsOnTheParentQueryConnection(bool $arrays): void
    {
        $owner = new PreloadFixtureInitializingParent();
        PreloadFixtureInitializingParent::$connections = [];
        $this->db->setOnQuery(function (QueryResult $result): void {
            if (str_contains($result->getLastPrepareQuery() ?? '', 'preload_parents')) {
                $this->db->selectConnection('observer');
            }
        });

        $owner->queryLimit()->orderBy('id', 'ASC')->withRelations('children');
        if ($arrays) { $owner->queryLimit()->returnAsArray(); }
        $parents = $owner->all()->list();

        $this->assertSame(['default', 'default', 'default'], PreloadFixtureInitializingParent::$connections);
        $this->assertSame('observer', $this->db->selectConnection());
        $this->db->selectConnection('default');
        $this->assertSame([11, 12], $arrays ? array_column($parents[0]['children'], 'id') : $this->childIds($parents[0]));
        $this->assertCount(2, $this->connection->queries);
    }

    public function testQueriesInitializeEntitiesOnTheQueryConnectionAndRestoreObserverSelection(): void
    {
        $owner = new PreloadFixtureInitializingParent();
        $this->db->setOnQuery(function (): void { $this->db->selectConnection('observer'); });
        $queries = [
            'all' => [static fn () => $owner->all(), 3],
            'findAll' => [static fn () => PreloadFixtureInitializingParent::findAll(tableName: 'preload_parents'), 3],
            'findRecord' => [static fn () => PreloadFixtureInitializingParent::findRecord(1, tableName: 'preload_parents'), 1],
        ];

        foreach ($queries as $entry => [$run, $count]) {
            $this->db->selectConnection('default');
            PreloadFixtureInitializingParent::$connections = [];
            $run();
            $this->assertSame(array_fill(0, $count, 'default'), PreloadFixtureInitializingParent::$connections, $entry);
            $this->assertSame('observer', $this->db->selectConnection(), $entry);
        }
        $this->db->selectConnection('default');
    }

    #[DataProvider('parentOutputModes')]
    public function testTargetConstructorConnectionChoiceMatchesLazyLoadingAndPrimesTheCorrectCache(bool $arrays): void
    {
        $otherDatabase = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $otherDatabase->exec('CREATE TABLE preload_children (id INTEGER, parent_id INTEGER, group_code TEXT, name TEXT, payload TEXT)');
        $otherDatabase->exec("INSERT INTO preload_children VALUES (111,1,'a','other child','[111]')");
        $other = new PreloadFixtureConnection($otherDatabase);
        $other->connectionName = 'other';
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($this->db,
            [Coroutine::getCid() => ['default' => $this->connection, 'other' => $other]]);

        // 现有按需加载流程先构造目标模型，再确定执行连接。
        $lazy = new PreloadFixtureParent(['id' => 1]);
        $this->assertSame([111], array_map(static fn(PreloadFixtureConstructorChild $child): int => $child->id, $lazy->constructorChildren()->list()));
        $this->assertCount(1, $other->queries);
        $this->assertSame([], $this->connection->queries);
        $this->db->selectConnection('default');

        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->withRelations('constructorChildren');
        if ($arrays) { $owner->queryLimit()->returnAsArray(); }
        $parents = $owner->all()->list();
        $this->assertSame('default', $this->db->selectConnection(), 'The preload scope must restore its caller connection.');
        $this->assertCount(1, $this->connection->queries);
        $this->assertCount(2, $other->queries);
        if ($arrays) {
            $this->assertSame([111], array_column($parents[0]['constructorChildren'], 'id'));
            $this->assertSame([], $parents[1]['constructorChildren']);
        } else {
            $this->assertSame([111], array_map(static fn(PreloadFixtureConstructorChild $child): int => $child->id, $parents[0]->constructorChildren()->list()));
            $this->assertSame([], $parents[1]->constructorChildren()->list());
        }
        $this->assertCount(2, $other->queries, 'Accessing the existing getter must hit the actual-connection preload.');
    }

    public function testInstanceConfigurationKeepsPerParentConditions(): void
    {
        $parents = $this->parents('dynamicChildren');

        $this->assertSame([12], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parents[0]->dynamicChildren()->list()));
        $this->assertSame([13], array_map(static fn(PreloadFixtureChild $child): int => $child->id, $parents[1]->dynamicChildren()->list()));
        $this->assertSame([], $parents[2]->dynamicChildren()->list());
        // 两种配置分别形成批查询，同条件的父实体共用一批。
        $this->assertCount(3, $this->connection->queries);
        $this->assertCount(3, $this->events);
    }

    public static function parentOutputModes(): array { return [[false], [true]]; }

    public function testPreloadingDoesNotExecuteGetterBusinessLogic(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->returnAsArray()->withRelations('redirectedChildren');
        $parents = $owner->all()->list();
        $this->assertSame([11, 12], array_column($parents[0]['redirectedChildren'], 'id'));
        $this->assertSame([13], array_column($parents[1]['redirectedChildren'], 'id'));
        $this->assertSame([], $parents[2]['redirectedChildren']);
        $this->assertCount(2, $this->connection->queries);
    }

    #[DataProvider('parentOutputModes')]
    public function testTargetTableNameIsResolvedForEachConfiguredParent(bool $arrays): void
    {
        $this->connection->database->exec('CREATE TABLE preload_archived_children AS SELECT * FROM preload_children WHERE 0');
        $this->connection->database->exec("INSERT INTO preload_archived_children VALUES (31,2,'a','archived child','[31]')");
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->withRelations('contextChildren');
        if ($arrays) { $owner->queryLimit()->returnAsArray(); }
        $parents = $owner->all()->list();

        $this->assertCount(3, $this->connection->queries, 'Both dynamic target tables must be loaded before any relation getter.');
        $ids = static fn(array $children): array => array_map(static fn($child): int => is_array($child) ? $child['id'] : $child->id, $children);
        $this->assertSame([11, 12], $ids($arrays ? $parents[0]['contextChildren'] : $parents[0]->contextChildren()->list()));
        $this->assertSame([31], $ids($arrays ? $parents[1]['contextChildren'] : $parents[1]->contextChildren()->list()));
        $this->assertSame([], $arrays ? $parents[2]['contextChildren'] : $parents[2]->contextChildren()->list());
        $this->assertCount(3, $this->connection->queries);
    }

    public function testEntityRelationsCanExplicitlyReturnArrayRows(): void
    {
        $parents = $this->parents(['arrayChildren', 'arrayProfile']);
        $children = $parents[0]->arrayChildren();
        $this->assertIsArray($children->first());
        $this->assertSame([11, 12], array_column($children->list(), 'id'));
        $this->assertSame('[11]', $children->first()['payload']);
        $this->assertSame(['id' => 21, 'parent_id' => 1, 'name' => 'profile-1'], $parents[0]->arrayProfile());
        $this->assertNull($parents[1]->arrayProfile());
        $this->assertSame([], $parents[2]->arrayChildren()->list());
        $this->assertCount(3, $this->connection->queries);
    }

    public function testDefaultPreloadProjectionUsesTheWildcardAndOnlyReturnsUserColumns(): void
    {
        $parents = $this->parents('arrayChildren');
        $this->assertMatchesRegularExpression('/^SELECT\s+\*,\s+/', $this->connection->queries[1]['sql']);
        $this->assertSame(['id', 'parent_id', 'group_code', 'name', 'payload'], array_keys($parents[0]->arrayChildren()->first()));
    }

    public function testRelationsRejectTotalCountBeforeRunningAnyRelationQuery(): void
    {
        try {
            (new PreloadFixtureParent(['id' => 1]))->countedChildren();
            $this->fail('Lazy relation with total count must fail');
        } catch (RuntimeError $error) {
            $this->assertSame('Relation does not support withTotalCount()', $error->getMessage());
        }
        $this->assertSame([], $this->connection->queries);
        try {
            $this->parents(['children', 'countedChildren']);
            $this->fail('Preloading with total count must fail');
        } catch (RuntimeError $error) {
            $this->assertSame('Relation does not support withTotalCount()', $error->getMessage());
        }
        $this->assertCount(1, $this->connection->queries);
        $this->assertCount(1, $this->events);
    }

    public function testRelationPreloadRejectsLimitBeforeRunningAnyRelationQuery(): void
    {
        $parent = new PreloadFixtureParent(['id' => 1]);
        $this->assertSame(12, $parent->latestChild()->first()->id);
        $this->connection->queries = $this->events = [];

        try {
            $this->parents(['children', 'latestChild']);
            $this->fail('Preloading with LIMIT must fail');
        } catch (RuntimeError $error) {
            $this->assertSame('Relation preload does not support limit()', $error->getMessage());
        }
        $this->assertCount(1, $this->connection->queries);
        $this->assertCount(1, $this->events);
    }

    public function testOneRelationRejectsExplicitLimitBeforeRunningSql(): void
    {
        $parent = new PreloadFixtureParent(['id' => 1]);
        $parent->queryLimit()->func(static fn (QueryBuilder $query) => $query->limit(1, 1));

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('One-to-one relation does not support limit()');
        try {
            $parent->profile();
        } finally {
            $this->assertSame([], $this->connection->queries);
        }
    }

    public function testPartiallyLoadedRelationEntitiesCannotWriteThroughDefaultPrimaryKey(): void
    {
        $parent = new PreloadFixtureParent(['id' => 1]);
        $preloaded = $this->parents('partialChildren')[0];
        foreach ([$parent, $preloaded] as $owner) {
            $child = $owner->partialChildren()->first();
            $this->assertSame(13, $child->id);
            $child->name = 'blocked';
            try {
                $child->update();
                $this->fail('A relation row without its primary key cannot be updated');
            } catch (RuntimeError $error) {
                $this->assertStringContainsString('without primary key value', $error->getMessage());
            }
        }
        $this->assertSame('child-13', $this->connection->database->query('SELECT name FROM preload_children WHERE id = 13')->fetchColumn());
    }

    public function testLazyRelationRequiresAnInitializedParentField(): void
    {
        $parent = new PreloadFixtureParent();
        unset($parent->id);
        try {
            $parent->children();
            $this->fail('An uninitialized parent field must fail');
        } catch (\Error $error) {
            $this->assertStringContainsString('must not be accessed before initialization', $error->getMessage());
        }
        $this->assertSame([], $this->connection->queries);
    }

    public function testExplicitEmptyRelationFieldsFailBeforeBatchQuery(): void
    {
        try {
            $this->parents('emptyProjectionChildren');
            $this->fail('Expected empty projection to fail');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('at least one column', $error->getMessage());
        }
        $this->assertCount(1, $this->connection->queries);
    }

    public function testSelectedChildFieldsCanOmitTheForeignKeyAndPreserveAnAlias(): void
    {
        $parents = $this->parents('selectedChildren');
        $rows = $parents[0]->selectedChildren()->list();
        $this->assertSame([
            ['id' => 11, 'name' => 'child-11', 'encoded' => '[11]'],
            ['id' => 12, 'name' => 'child-12', 'encoded' => '[12]'],
        ], $rows);
        $this->assertSame([13], array_column($parents[1]->selectedChildren()->list(), 'id'));
        $this->assertSame([], $parents[2]->selectedChildren()->list());
        $this->assertCount(2, $this->connection->queries);
    }

    public function testArrayRelationNamesCannotOverwriteSelectedParentFields(): void
    {
        $this->connection->database->exec("ALTER TABLE preload_parents ADD COLUMN children TEXT DEFAULT 'original field'");
        $owner = new PreloadFixtureCollisionParent();
        $owner->queryLimit()->returnAsArray()->withRelations('children');
        try { $owner->all(); $this->fail('A relation must not overwrite a selected parent field'); }
        catch (RuntimeError $error) { $this->assertStringContainsString('conflict', strtolower($error->getMessage())); }
        $this->assertCount(1, $this->connection->queries);
        $this->assertSame('original field', $this->connection->database->query('SELECT children FROM preload_parents WHERE id=1')->fetchColumn());
    }

    public function testMoreThan250UniqueKeysAreLoadedInCompleteBoundedBatches(): void
    {
        $insertParent = $this->connection->database->prepare('INSERT INTO preload_parents VALUES (?, ?, ?)');
        $insertChild = $this->connection->database->prepare('INSERT INTO preload_children VALUES (?, ?, ?, ?, ?)');
        for ($id = 4; $id <= 505; $id++) {
            $insertParent->execute([$id, 'group-' . $id, 'parent-' . $id]);
            $insertChild->execute([1000 + $id, $id, 'group-' . $id, 'child-' . $id, '[' . (1000 + $id) . ']']);
        }
        $parents = $this->parents('children');
        $seen = [];
        foreach ($parents as $parent) { $seen[$parent->id] = $this->childIds($parent); }
        $this->assertCount(505, $seen);
        $this->assertSame([11, 12], $seen[1]);
        $this->assertSame([], $seen[3]);
        $this->assertSame([1250], $seen[250]);
        $this->assertSame([1251], $seen[251]);
        $this->assertSame([1500], $seen[500]);
        $this->assertSame([1505], $seen[505]);
        $this->assertSame(505, array_sum(array_map('count', $seen)));
        $this->assertCount(4, $this->connection->queries); // 父表执行一次 SELECT，关联键再按 250/250/5 分批查询。
        $this->assertCount(4, $this->events);
    }

    public function testRelationMethodNamesAreCanonicalizedAndDeduplicatedCaseInsensitively(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->returnAsArray()->orderBy('id', 'ASC')->withRelations(['CHILDREN', 'children', 'Children']);
        $parents = $owner->all()->list();
        $this->assertArrayHasKey('children', $parents[0]);
        $this->assertArrayNotHasKey('CHILDREN', $parents[0]);
        $this->assertSame([11, 12], array_column($parents[0]['children'], 'id'));
        $this->assertCount(2, $this->connection->queries);
    }

    public function testCursorChunksPreloadOnlyTheirCurrentPage(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->withRelations('children');
        $seen = [];
        $this->assertSame(3, $owner->chunkById(function (PreloadFixtureParent $parent) use (&$seen): void {
            $seen[$parent->id] = $this->childIds($parent);
        }, 2));
        $this->assertSame([1 => [11, 12], 2 => [13], 3 => []], $seen);
        $this->assertCount(4, $this->connection->queries);
        $this->assertCount(4, $this->events);
    }

    public function testArrayRowsAttachOneAndManyRelationsWhileTheParentKeyCanBeHidden(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->fields(['id', 'name'], true)->hideFields('id')
            ->withRelations(['children', 'profile']);
        $parents = $owner->all()->list();

        $this->assertSame(['name', 'children', 'profile'], array_keys($parents[0]));
        $this->assertSame('first', $parents[0]['name']);
        $this->assertSame([11, 12], array_column($parents[0]['children'], 'id'));
        $this->assertSame('[11]', $parents[0]['children'][0]['payload']);
        $this->assertSame(['id' => 21, 'parent_id' => 1, 'name' => 'profile-1'], $parents[0]['profile']);
        $this->assertNull($parents[1]['profile']);
        $this->assertSame([], $parents[2]['children']);
        $this->assertCount(3, $this->connection->queries);
        $this->assertCount(3, $this->events);
    }

    public function testOrdinaryPaginationPreloadsOnlyItsReturnedParents(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->page(1, false, 2)->withRelations('children');
        $parents = $owner->all()->list();
        $this->assertSame([1, 2], array_map(static fn(PreloadFixtureParent $parent): int => $parent->id, $parents));
        $this->assertSame([11, 12], $this->childIds($parents[0]));
        $this->assertSame([13], $this->childIds($parents[1]));
        $this->assertCount(2, $this->connection->queries);
    }

    public function testOffsetChunksAlsoPreloadEachPageWithoutPerParentQueries(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->orderBy('id', 'ASC')->withRelations('children');
        $seen = [];
        $this->assertNull($owner->chunk(function (PreloadFixtureParent $parent) use (&$seen): void {
            $seen[$parent->id] = $this->childIds($parent);
        }, 2));
        $this->assertSame([1 => [11, 12], 2 => [13], 3 => []], $seen);
        $this->assertCount(4, $this->connection->queries);
        $this->assertCount(4, $this->events);
    }

    public function testPreloadConfigurationIsConsumedByTheQuery(): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->withRelations('children');
        $owner->all();
        $this->assertCount(2, $this->connection->queries);
        $owner->all();
        $this->assertCount(3, $this->connection->queries);
    }

    public function testRepeatedRelationNamesDoNotIssueDuplicateBatchQueries(): void
    {
        $parents = $this->parents(['children', 'children']);
        $this->assertSame([11, 12], $this->childIds($parents[0]));
        $this->assertCount(2, $this->connection->queries);
    }

    public static function invalidParentSelections(): array
    {
        return [
            'missing primary key' => [['name'], false, false, 'dynamicChildren'],
            'renamed primary key' => [['id AS other', 'name'], false, false, 'children'],
            'hidden primary key for entities' => [null, true, false, 'children'],
            'missing primary key for array output' => [['name'], false, true, 'dynamicChildren'],
            'missing explicit relation field' => [['id', 'name'], false, false, 'groupChildren'],
        ];
    }

    #[DataProvider('invalidParentSelections')]
    public function testRelationKeysMustBeAvailableUnderTheirOriginalName(?array $fields, bool $hideKey, bool $arrays, string $relation): void
    {
        $owner = new PreloadFixtureParent();
        $owner->queryLimit()->fields($fields, $arrays)->withRelations($relation);
        if ($hideKey) { $owner->queryLimit()->hideFields('id'); }
        try { $owner->all(); $this->fail('A missing relation field must not silently create empty groups'); }
        catch (RuntimeError $error) {
            $this->assertStringContainsString($hideKey ? 'cannot be hidden' : 'under its original name', $error->getMessage());
        }
        $this->assertCount(1, $this->connection->queries);
        $this->assertNull($owner->queryLimit()->getFields());
        $this->assertNull($owner->queryLimit()->getHideFields());
    }

    public function testUnknownRelationFailsClearlyWithoutTargetQueries(): void
    {
        $owner = new PreloadFixtureParent();
        try { $owner->queryLimit()->withRelations('missingRelation')->returnEntity()->all(); $this->fail('Unknown relation must fail'); }
        catch (\ReflectionException) { $this->addToAssertionCount(1); }
        $this->assertLessThanOrEqual(1, count($this->connection->queries));
    }

    public function testMethodsWithoutARelateAttributeAreRejectedBeforeInvocation(): void
    {
        PreloadFixtureParent::$nonRelationCalls = 0;
        $owner = new PreloadFixtureParent();
        try { $owner->queryLimit()->withRelations('nonRelationOperation')->returnEntity()->all(); $this->fail('Only declared relation wrappers may be preloaded'); }
        catch (RuntimeError|\InvalidArgumentException) { $this->addToAssertionCount(1); }
        $this->assertSame(0, PreloadFixtureParent::$nonRelationCalls);
        $this->assertSame(3, (int)$this->connection->database->query('SELECT COUNT(*) FROM preload_parents')->fetchColumn());
        foreach ($this->connection->queries as $query) { $this->assertStringNotContainsString('DELETE', $query['sql']); }
    }
}

class PreloadFixtureParent extends AbstractEntity
{
    public static int $nonRelationCalls = 0;
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?string $group_code;
    #[Property] public ?string $name;
    public function tableName(): string { return 'preload_parents'; }

    public function nonRelationOperation() { self::$nonRelationCalls++; return $this->delete(); }

    #[Relate(PreloadFixtureChild::class, 'parent_id', cacheResult: true, type: RelationType::Many)]
    public function children() { return $this->relateMany(); }

    #[Relate(PreloadFixtureSwitchingChild::class, 'parent_id', type: RelationType::Many, configure: 'orderSwitchingChildren')]
    public function switchingChildren() { return $this->relateMany(); }

    protected function orderSwitchingChildren(QueryBuilder $query): void
    {
        $query->orderBy('id', 'ASC');
    }

    #[Relate(PreloadFixtureChild::class, 'parent_id', type: RelationType::Many)]
    public function redirectedChildren()
    {
        $original = $this->id;
        $this->id = 2;
        try { return $this->relateMany(); }
        finally { $this->id = $original; }
    }

    #[Relate(PreloadFixtureProfile::class, 'parent_id', cacheResult: true, type: RelationType::One)]
    public function profile() { return $this->relateOne(); }

    #[Relate(PreloadFixtureTrackedProfile::class, 'name', type: RelationType::One, selfProperty: 'group_code')]
    public function groupProfile() { return $this->relateOne(); }

    #[Relate(PreloadFixtureChild::class, 'group_code', selfProperty: 'group_code', cacheResult: true, type: RelationType::Many)]
    public function groupChildren() { return $this->relateMany(); }

    #[Relate(PreloadFixtureChild::class, 'parent_id', selfProperty: 'group_code', cacheResult: true, type: RelationType::Many)]
    public function numericChildren() { return $this->relateMany(); }

    #[Relate(PreloadFixtureChild::class, 'parent_id', cacheResult: true, type: RelationType::Many, configure: 'configureFilteredChildren')]
    public function filteredChildren() { return $this->relateMany(); }

    protected function configureFilteredChildren(QueryBuilder $query): void
    { $query->where('name', 'child-11')->orWhere('name', 'child-13'); }

    #[Relate(PreloadFixtureChild::class, 'parent_id', cacheResult: true, type: RelationType::Many, configure: 'configureDynamicChildren')]
    public function dynamicChildren() { return $this->relateMany(); }

    protected function configureDynamicChildren(QueryBuilder $query): void
    { $query->where('id', $this->id === 1 ? 12 : 0, '>='); }

    #[Relate(PreloadFixtureDynamicChild::class, 'parent_id', cacheResult: true, type: RelationType::Many, configure: 'configureContextChildren')]
    public function contextChildren() { return $this->relateMany(); }

    protected function configureContextChildren(QueryBuilder $query): void
    { PreloadFixtureDynamicChild::$contextTable = $this->id === 2 ? 'preload_archived_children' : 'preload_children'; }

    #[Relate(PreloadFixtureChild::class, 'parent_id', cacheResult: true, type: RelationType::Many, configure: 'configureArrayChildren', returnAsArray: true)]
    public function arrayChildren() { return $this->relateMany(); }

    protected function configureArrayChildren(QueryBuilder $query): void
    {  }

    #[Relate(PreloadFixtureProfile::class, 'parent_id', cacheResult: true, type: RelationType::One, configure: 'configureArrayProfile', returnAsArray: true)]
    public function arrayProfile() { return $this->relateOne(); }

    protected function configureArrayProfile(QueryBuilder $query): void
    {  }

    #[Relate(PreloadFixtureChild::class, 'parent_id', cacheResult: true, type: RelationType::Many, configure: 'configureSelectedChildren', returnAsArray: true)]
    public function selectedChildren() { return $this->relateMany(); }

    protected function configureSelectedChildren(QueryBuilder $query): void
    { $query->fields(['id', 'name', 'payload AS encoded']); }

    #[Relate(PreloadFixtureChild::class, 'parent_id', type: RelationType::Many, configure: 'configureCountedChildren')]
    public function countedChildren() { return $this->relateMany(); }

    protected function configureCountedChildren(QueryBuilder $query): void
    {
        $query->withTotalCount();
    }

    #[Relate(PreloadFixtureChild::class, 'parent_id', type: RelationType::Many, configure: 'configureLatestChild')]
    public function latestChild() { return $this->relateMany(); }

    protected function configureLatestChild(QueryBuilder $query): void
    {
        $query->orderBy('id', 'DESC')->limit(1);
    }

    #[Relate(PreloadFixtureDefaultKeyChild::class, 'parent_id', type: RelationType::Many, configure: 'configurePartialChildren')]
    public function partialChildren() { return $this->relateMany(); }

    protected function configurePartialChildren(QueryBuilder $query): void
    {
        $query->fields(['name']);
    }

    #[Relate(PreloadFixtureChild::class, 'parent_id', type: RelationType::Many, configure: 'configureEmptyProjection')]
    public function emptyProjectionChildren() { return $this->relateMany(); }

    protected function configureEmptyProjection(QueryBuilder $query): void
    {
        $query->fields([]);
    }

    #[Relate(PreloadFixtureConstructorChild::class, 'parent_id', cacheResult: true, type: RelationType::Many)]
    public function constructorChildren() { return $this->relateMany(); }


}

#[OnInitialize('recordConnection')]
class PreloadFixtureInitializingParent extends PreloadFixtureParent
{
    public static array $connections = [];

    protected function recordConnection(): void
    {
        self::$connections[] = FastDb::getInstance()->selectConnection();
    }
}

class PreloadFixtureCollisionParent extends PreloadFixtureParent
{
    #[Property] public ?string $children;
}

class PreloadFixtureChild extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?int $parent_id;
    #[Property] public ?string $group_code;
    #[Property] public ?string $name;
    #[Property(convertObject: ConvertList::class)] public ?ConvertList $payload;
    public function tableName(): string { return 'preload_children'; }
}

class PreloadFixtureSwitchingChild extends PreloadFixtureChild
{
    public string $hydrationConnection;

    public function __construct(?array $data = null)
    {
        parent::__construct($data);
        if ($data !== null) {
            $db = FastDb::getInstance();
            $this->hydrationConnection = $db->selectConnection();
            $db->selectConnection('other');
        }
    }
}

class PreloadFixtureProfile extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?int $parent_id;
    #[Property] public ?string $name;
    public function tableName(): string { return 'preload_profiles'; }
}

class PreloadFixtureTrackedProfile extends PreloadFixtureProfile
{
    public static int $constructed = 0;
    public int $constructionOrder;
    public string $hydrationConnection;

    public function __construct(public ?array $constructorData = null)
    {
        parent::__construct($constructorData);
        $this->constructionOrder = ++self::$constructed;
    }

    public function setData(array $data, bool $mergeCompare = false): static
    {
        $this->hydrationConnection = FastDb::getInstance()->selectConnection();
        return parent::setData($data, $mergeCompare);
    }
}

final class PreloadFixtureDefaultKeyChild extends PreloadFixtureChild
{
    #[Property(defaultValue: 13)]
    #[PrimaryKey]
    public int $id;
}

class PreloadFixtureDynamicChild extends PreloadFixtureChild
{
    public static string $contextTable = 'preload_children';
    public function tableName(): string { return self::$contextTable; }
}

class PreloadFixtureConstructorChild extends PreloadFixtureChild
{
    public function __construct(array|null $data = null)
    {
        parent::__construct($data);
        FastDb::getInstance()->selectConnection('other');
    }
}

class PreloadFixtureConnection extends Connection
{
    public array $queries = [];
    public function __construct(public \PDO $database) { parent::__construct(new Config()); }
    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $sql = $builder->getLastPrepareQuery();
        $parameters = $builder->getLastBindParams();
        $this->queries[] = ['sql' => $sql, 'parameters' => $parameters];
        $statement = $this->database->prepare($sql);
        $statement->execute($parameters);
        $this->lastAffectRows = $statement->rowCount(); $this->lastInsertId = 0;
        return $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_ASSOC) : true;
    }
}

class PreloadScopedParent extends PreloadFixtureParent
{
    private ?\WeakReference $scopedQuery = null;
    public function queryLimit(): Query
    {
        $query = parent::queryLimit();
        if ($this->scopedQuery?->get() !== $query) {
            $this->scopedQuery = \WeakReference::create($query);
            $query->where('active',1);
        }
        return $query;
    }
}
class PreloadAppendingScopeParent extends PreloadFixtureParent
{
    public function queryLimit(): Query
    {
        return parent::queryLimit()->where('active', 1);
    }
}
class PreloadInheritedScopedParent extends PreloadScopedParent {}

class ReentrantProfileParent extends PreloadFixtureParent
{
    #[Relate(ReentrantProfile::class,'parent_id',type:RelationType::One)]
    public function reentrantProfile() { return $this->relateOne(); }

    #[Relate(ReentrantProfile::class, 'parent_id', type: RelationType::Many)]
    public function reentrantProfiles() { return $this->relateMany(); }
}
class ReentrantProfile extends AbstractEntity
{
    public static ?PreloadFixtureParent $parent = null;
    public static ?int $parentCount = null;
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public int $parent_id;
    #[Property] public ?string $name;
    public function setData(?array $data, bool $mergeCompare = false): static
    {
        self::$parentCount = count(self::$parent->all());
        return parent::setData($data, $mergeCompare);
    }
    public function tableName(): string { return 'preload_profiles'; }
}
