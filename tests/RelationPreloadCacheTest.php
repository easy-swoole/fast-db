<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Internal\RelationLoader;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelationPreloadCacheTest extends TestCase
{
    private mixed $previousDb;
    private FastDb $db;
    private int $queries = 0;
    private int $resets = 0;

    protected function setUp(): void
    {
        $singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $this->previousDb = $singleton->getValue();
        $this->db = new FastDb(); $singleton->setValue(null, $this->db);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(FastDb::class, 'instance'))->setValue(null, $this->previousDb);
    }

    public function testExplicitOneAndManyRemainReadableWithLazyCachingDisabled(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader();
        $one = new PreloadCacheChild(['id' => 10, 'parent_id' => 1, 'label' => 'preloaded']);
        $many = new ListResult([$one]);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::One), RelationType::One, false, 'default', $one);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::Many), RelationType::Many, false, 'default', $many);
        $this->assertSame($one, $this->load($loader, $parent, $relation, RelationType::One));
        $this->assertSame($many, $this->load($loader, $parent, $relation, RelationType::Many));
        $this->assertSame($one, $this->load($loader, $parent, $relation, RelationType::One));
        $this->assertFalse($relation->cacheResult);
        $this->assertSame(0, $this->queries);
        $this->assertSame(3, $this->resets);
        $this->assertNull($parent->queryLimit()->getFields());
    }

    public function testNullAndEmptyCollectionsAreActualPreloadHits(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader(); $empty = new ListResult([]);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::One), RelationType::One, false, 'default', null);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::Many), RelationType::Many, false, 'default', $empty);
        $this->assertNull($this->load($loader, $parent, $relation, RelationType::One));
        $this->assertSame($empty, $this->load($loader, $parent, $relation, RelationType::Many));
        $this->assertSame(0, $this->queries);
    }

    public static function changedShapes(): array
    {
        return array_map(static fn ($shape) => [$shape], ['where', 'or', 'page', 'fields', 'array-mode', 'table', 'connection', 'parent-key', 'target', 'kind', 'options']);
    }

    #[DataProvider('changedShapes')]
    public function testChangedLookupShapesDoNotReadThePreloadedView(string $shape): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader();
        if ($shape === 'kind') { $parent->queryLimit()->page(null, false, 2); }
        $lookup = $this->lookup($parent, $relation, RelationType::Many);
        $loaded = new ListResult([new PreloadCacheChild(['id' => 10, 'parent_id' => 1, 'label' => 'preloaded'])]);
        $loader->prime($relation, $lookup, RelationType::Many, false, 'default', $loaded);
        $table = null; $kind = RelationType::Many;
        switch ($shape) {
            case 'where': $parent->queryLimit()->where('label', 'filtered'); break;
            case 'or': $parent->queryLimit()->where('id', 4)->orWhere('label', 'filtered'); break;
            case 'page': $parent->queryLimit()->page(2, false, 3); break;
            case 'fields': $parent->queryLimit()->fields(['id', 'label']); break;
            case 'array-mode': $parent->queryLimit()->returnAsArray(); break;
            case 'table': $table = 'other_preload_children'; break;
            case 'connection': $this->db->selectConnection('other'); break;
            case 'parent-key': $parent->id = 2; break;
            case 'target': $relation = $this->relation(PreloadCacheAlternateChild::class); break;
            case 'kind': $kind = RelationType::One; break;
            case 'options': $parent->queryLimit()->func(static fn ($query) => $query->setQueryOption('SQL_BUFFER_RESULT')); break;
        }
        $actual = $this->load($loader, $parent, $relation, $kind, $table);
        $this->assertNotSame($loaded, $actual);
        $this->assertSame(1, $this->queries);
        $this->assertSame(1, $this->resets);
    }

    public function testChangedParentKeyDoesNotEnableOrdinaryLazyCaching(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader(); $loaded = new ListResult([]);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::Many), RelationType::Many, false, 'default', $loaded);
        $parent->id = 2;
        $this->load($loader, $parent, $relation, RelationType::Many);
        $this->load($loader, $parent, $relation, RelationType::Many);
        $this->assertSame(2, $this->queries);
        $parent->id = 1;
        $this->assertSame($loaded, $this->load($loader, $parent, $relation, RelationType::Many));
        $this->assertSame(2, $this->queries);
    }

    public function testPrimingUsesThePassedConnectionAndFreezesTheLookupInsteadOfRetainingIt(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader(); $loaded = new ListResult([]);
        $lookup = $this->lookup($parent, $relation, RelationType::Many);
        $this->db->selectConnection('other');
        $loader->prime($relation, $lookup, RelationType::Many, false, 'default', $loaded);
        $lookup->raw('SELECT completely_different');
        $this->load($loader, $parent, $relation, RelationType::Many);
        $this->assertSame(1, $this->queries);
        $this->db->selectConnection('default');
        $this->assertSame($loaded, $this->load($loader, $parent, $relation, RelationType::Many));
        $this->assertSame(1, $this->queries);
    }

    public function testPrimeOverridesAnExistingLazyCacheAndClearRemovesBoth(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $relation->cacheResult = true; $loader = new RelationLoader();
        $lazy = $this->load($loader, $parent, $relation, RelationType::One);
        $this->assertSame($lazy, $this->load($loader, $parent, $relation, RelationType::One));
        $this->assertSame(1, $this->queries);
        $loaded = new PreloadCacheChild(['id' => 11, 'parent_id' => 1, 'label' => 'explicit']);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::One), RelationType::One, false, 'default', $loaded);
        $this->assertSame($loaded, $this->load($loader, $parent, $relation, RelationType::One));
        $loader->clear();
        $this->assertNotSame($loaded, $this->load($loader, $parent, $relation, RelationType::One));
        $this->assertSame(2, $this->queries);
    }

    public function testClearingACloneDoesNotClearTheOriginalAndResultsKeepExistingShallowIdentity(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader();
        $loaded = new PreloadCacheChild(['id' => 11, 'parent_id' => 1, 'label' => 'explicit']);
        $loader->prime($relation, $this->lookup($parent, $relation, RelationType::One), RelationType::One, false, 'default', $loaded);
        $clone = clone $loader;
        $this->assertSame($loaded, $this->load($clone, $parent, $relation, RelationType::One));
        $clone->clear();
        $this->assertSame($loaded, $this->load($loader, $parent, $relation, RelationType::One));
        $this->assertNotSame($loaded, $this->load($clone, $parent, $relation, RelationType::One));
        $this->assertSame(1, $this->queries);
    }

    public function testPrimingDoesNotRetainTheParentOrTheLookupBuilder(): void
    {
        $parent = $this->parent(); $relation = $this->relation(); $loader = new RelationLoader();
        $lookup = $this->lookup($parent, $relation, RelationType::Many);
        $parentRef = \WeakReference::create($parent); $queryRef = \WeakReference::create($lookup);
        $loader->prime($relation, $lookup, RelationType::Many, false, 'default', new ListResult([]));
        unset($parent, $lookup); gc_collect_cycles();
        $this->assertNull($parentRef->get());
        $this->assertNull($queryRef->get());
    }

    private function parent(): PreloadCacheParent { return new PreloadCacheParent(['id' => 1]); }
    private function relation(string $target = PreloadCacheChild::class): Relate { return new Relate($target, 'parent_id', RelationType::Many, selfProperty: 'id'); }
    private function lookup(PreloadCacheParent $parent, Relate $relation, RelationType $kind): QueryBuilder
    {
        $query = clone $parent->queryLimit()->__getQueryBuilder();
        $fields = $parent->queryLimit()->getFields()['fields'] ?? null;
        return $query->groupExistingWhere()->where($relation->targetProperty, $parent->id)
            ->get((new $relation->targetEntity())->tableName(), $kind === RelationType::One ? 2 : null, $fields);
    }
    private function load(RelationLoader $loader, PreloadCacheParent $parent, Relate $relation, RelationType $kind, ?string $table = null): mixed
    {
        $reset = static function () use ($parent): void {
            (new \ReflectionProperty(AbstractEntity::class, 'queryBuilder'))->setValue($parent, null);
        };
        try {
            return $loader->load($parent, $relation, $table, $kind,
                function (QueryBuilder $query) use ($parent): QueryResult {
                    $this->queries++;
                    $result = new QueryResult(microtime(true));
                    $result->setResult([['id' => 99, 'parent_id' => $parent->id, 'label' => 'database']]);
                    return $result;
                }, $reset, static fn () => null);
        } finally {
            $this->resets++;
            $reset();
        }
    }
}

class PreloadCacheParent extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    public function tableName(): string { return 'preload_parents'; }
}
class PreloadCacheChild extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public int $parent_id;
    #[Property] public string $label;
    public function tableName(): string { return 'preload_children'; }
}
class PreloadCacheAlternateChild extends PreloadCacheChild {}
