<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\Page;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryLazyBuilderTest extends TestCase
{
    private function builderState(Query $query): ?QueryBuilder
    {
        return (new \ReflectionProperty(Query::class, 'queryBuilder'))->getValue($query);
    }

    public function testPlainSerializationDoesNotCreateAQueryOrRetainTheEntityUntilGc(): void
    {
        $entity = new LazyOutputOwner(['id' => 1, 'secret' => 'private']);
        $state = new \ReflectionProperty(AbstractEntity::class, 'queryBuilder');
        $this->assertSame(['id' => 1, 'secret' => 'private'], $entity->toArray());
        $this->assertSame('{"id":1,"secret":"private"}', json_encode($entity, JSON_THROW_ON_ERROR));
        $entity->secret = null;
        $this->assertSame(['id' => 1], $entity->toArray(true));
        $this->assertNull($state->getValue($entity));
        $reference = \WeakReference::create($entity);
        unset($entity);
        $this->assertNull($reference->get());
    }

    public static function outputOverrides(): array
    {
        return [[LazyCustomOutputOwner::class], [LazyInheritedCustomOutputOwner::class]];
    }

    #[DataProvider('outputOverrides')]
    public function testSerializationStillCallsDirectAndInheritedQueryLimitOverrides(string $class): void
    {
        LazyCustomOutputOwner::$calls = 0;
        $entity = new $class(['id' => 1, 'secret' => 'private']);
        $this->assertSame(['id' => 1], $entity->toArray());
        $this->assertSame(1, LazyCustomOutputOwner::$calls);
        $this->assertSame(['id' => 1], $entity->toArray());
        $this->assertSame(2, LazyCustomOutputOwner::$calls);
    }

    public function testOutputOptionsDoNotAllocateASqlBuilder(): void
    {
        $owner = new LazyQueryOwner();
        $query = new Query($owner);
        $query->fields(['id'])->returnAsArray()->hideFields('secret')->persistFieldLimit();

        $this->assertNull($this->builderState($query));
        $this->assertSame(['fields' => ['id'], 'returnAsArray' => true], $query->getFields());
        $this->assertSame(['secret'], $query->getHideFields());
        $this->assertTrue($query->isPersistFieldLimit());
        $this->assertSame($owner, $query->returnEntity());
    }

    public function testUnusedClonesAndReboundQueriesKeepIndependentSelectionWithoutAllocatingBuilders(): void
    {
        $owner = new LazyQueryOwner();
        $other = new LazyQueryOwner();
        $query = (new Query($owner))->fields(['id'])->hideFields('secret')->persistFieldLimit();
        $clone = clone $query;
        $rebound = $query->forEntity($other);
        $clean = $query->withoutSqlConditions();
        $query->fields(['name'])->hideFields('other')->persistFieldLimit(false);

        foreach ([$query, $clone, $rebound, $clean] as $copy) {
            $this->assertNull($this->builderState($copy));
        }
        foreach ([$clone, $rebound, $clean] as $copy) {
            $this->assertSame(['fields' => ['id'], 'returnAsArray' => false], $copy->getFields());
            $this->assertSame(['secret'], $copy->getHideFields());
            $this->assertTrue($copy->isPersistFieldLimit());
        }
        $this->assertSame($owner, $clone->returnEntity());
        $this->assertSame($other, $rebound->returnEntity());
        $this->assertSame($owner, $clean->returnEntity());
    }

    public function testExistingBuildersKeepTheirIdentityAndCloneTheirSqlState(): void
    {
        $owner = new LazyQueryOwner();
        $other = new LazyQueryOwner();
        $query = (new Query($owner))->where('status', 1)->orderBy('id', 'DESC')->fields(['id']);
        $held = $query->__getQueryBuilder();
        $this->assertSame($held, $query->__getQueryBuilder());
        $clone = clone $query;
        $rebound = $query->forEntity($other);
        $this->assertNotSame($held, $clone->__getQueryBuilder());
        $this->assertNotSame($held, $rebound->__getQueryBuilder());
        $clone->where('score', 10, '>');
        $rebound->where('score', 20, '>');
        $held->get('items');
        $clone->__getQueryBuilder()->get('items');
        $rebound->__getQueryBuilder()->get('items');

        $this->assertSame([1], $held->getLastBindParams());
        $this->assertSame([1, 10], $clone->__getQueryBuilder()->getLastBindParams());
        $this->assertSame([1, 20], $rebound->__getQueryBuilder()->getLastBindParams());
        $this->assertSame($held, $query->__getQueryBuilder());
        $this->assertSame($other, $rebound->returnEntity());
    }

    public function testWithoutSqlConditionsPreservesTheOldQueryAndBuilderReferences(): void
    {
        $query = (new Query(new LazyQueryOwner()))->where('id', 7)->page(2, true, 5)
            ->fields(['id'])->hideFields('secret')->persistFieldLimit();
        $held = $query->__getQueryBuilder();
        $clean = $query->withoutSqlConditions();

        $this->assertNotSame($query, $clean);
        $this->assertNull($this->builderState($clean));
        $cleanBuilder = $clean->__getQueryBuilder();
        $this->assertNotSame($held, $cleanBuilder);
        $cleanBuilder->get('items');
        $held->get('items');
        $this->assertSame([], $cleanBuilder->getLastBindParams());
        $this->assertSame([], $cleanBuilder->getLastQueryOptions());
        $this->assertStringNotContainsString('WHERE', $cleanBuilder->getLastPrepareQuery());
        $this->assertStringNotContainsString('LIMIT', $cleanBuilder->getLastPrepareQuery());
        $this->assertSame([7], $held->getLastBindParams());
        $this->assertStringContainsString('LIMIT 5, 5', $held->getLastPrepareQuery());
        $this->assertSame(['fields' => ['id'], 'returnAsArray' => false], $clean->getFields());
        $this->assertSame(['secret'], $clean->getHideFields());
        $this->assertTrue($clean->isPersistFieldLimit());
    }

    public static function sqlOperations(): array
    {
        return [
            'group' => [static fn(Query $q) => $q->groupBy('id')],
            'order' => [static fn(Query $q) => $q->orderBy('id')],
            'where' => [static fn(Query $q) => $q->where('id', 1)],
            'required' => [static fn(Query $q) => $q->whereRequired('id', 1)],
            'or' => [static fn(Query $q) => $q->orWhere('id', 1)],
            'join' => [static fn(Query $q) => $q->join('owners o', 'o.id=i.ownerId', 'LEFT')],
            'callback' => [static fn(Query $q) => $q->func(static fn(QueryBuilder $b) => $b->where('id', 1))],
            'lock' => [static fn(Query $q) => $q->selectForUpdate()],
            'limit' => [static fn(Query $q) => $q->limit(3)],
        ];
    }

    #[DataProvider('sqlOperations')]
    public function testSqlOperationsInitializeTheirOwnBuilderWithoutCallingAnOverriddenPublicGetter(callable $operation): void
    {
        $query = new LazyGetterOverrideQuery(new LazyQueryOwner());
        $this->assertNull($this->builderState($query));
        $this->assertSame($query, $operation($query));
        $this->assertInstanceOf(QueryBuilder::class, $this->builderState($query));
    }

    public function testInvalidPageIsNotInterpretedAsLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Query(new LazyQueryOwner()))->page(0);
    }

    public static function pages(): array
    {
        return [
            'limit' => [null, false, 10], 'counted limit' => [null, true, 20],
            'zero page' => [0, false, 10], 'negative page' => [-2, false, 3],
            'third page' => [3, true, 20], 'empty page' => [1, false, 0],
            'negative size' => [2, false, -1], 'maximum size' => [1, false, PHP_INT_MAX],
            'offset overflow' => [PHP_INT_MAX, false, 2],
        ];
    }

    #[DataProvider('pages')]
    public function testPageMatchesThePageValueObject(?int $page, bool $total, int $size): void
    {
        $capture = static function (callable $operation): array {
            try {
                $builder = $operation();
                $builder->get('items');
                return [$builder->getLastPrepareQuery(), $builder->getLastBindParams(), $builder->getLastQueryOptions()];
            } catch (\Throwable $error) {
                return [$error::class];
            }
        };
        $expected = $capture(static function () use ($page, $total, $size): QueryBuilder {
            $builder = new QueryBuilder();
            $builder->limit(...(new Page($page, $total, $size))->toLimitArray());
            if ($total) { $builder->withTotalCount(); }
            return $builder;
        });
        $actual = $capture(static fn() => (new Query(new LazyQueryOwner()))->page($page, $total, $size)->__getQueryBuilder());
        $this->assertSame($expected, $actual);
    }
}

class LazyQueryOwner extends AbstractEntity
{
    public function tableName(): string { return 'lazy_query_test'; }
}

class LazyGetterOverrideQuery extends Query
{
    public function __getQueryBuilder(): QueryBuilder
    {
        throw new \LogicException('Internal SQL methods must preserve their original private builder dispatch.');
    }
}

class LazyOutputOwner extends AbstractEntity
{
    #[Property(isPrimaryKey: true)] public int $id;
    #[Property] public ?string $secret;
    public function tableName(): string { return 'lazy_output'; }
}

class LazyCustomOutputOwner extends LazyOutputOwner
{
    public static int $calls = 0;
    public function queryLimit(): Query
    {
        self::$calls++;
        return parent::queryLimit()->hideFields('secret');
    }
}

class LazyInheritedCustomOutputOwner extends LazyCustomOutputOwner {}
