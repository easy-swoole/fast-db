<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class ChunkTest extends TestCase
{
    public function testChunkPreservesConditionsAndOutputWithoutCountingEveryPage(): void
    {
        $this->withDatabase([
            [['id' => 1, 'name' => 'one'], ['id' => 2, 'name' => 'two']],
            [['id' => 3, 'name' => 'three']],
        ], function (array &$queries, array &$rawQueries): void {
            $entity = new ChunkEntity();
            $entity->queryLimit()->where('status', 1)->orderBy('id', 'ASC')
                ->fields(['id', 'name'])->hideFields('name')->persistFieldLimit();
            $items = [];
            $entity->chunk(function (ChunkEntity $item) use (&$items): void {
                $items[] = $item->toArray();
            }, 2);

            $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $items);
            $this->assertSame([], $rawQueries, 'Chunk must not query FOUND_ROWS().');
            $this->assertCount(2, $queries);
            foreach ($queries as $page => [$sql, $params, $options]) {
                $this->assertStringNotContainsString('SQL_CALC_FOUND_ROWS', $sql);
                $this->assertNotContains('SQL_CALC_FOUND_ROWS', $options);
                $this->assertMatchesRegularExpression('/SELECT\s+`?id`?,\s*`?name`?\s+FROM/', $sql);
                $this->assertMatchesRegularExpression('/WHERE\s+`status`\s*=\s*\?/', $sql);
                $this->assertMatchesRegularExpression('/ORDER BY\s+`?id`?\s+ASC/', $sql);
                $this->assertStringContainsString('LIMIT ' . ($page * 2) . ', 2', $sql);
                $this->assertSame([1], $params);
            }
            $this->assertSame([], $entity->queryLimit()->__getQueryBuilder()->getQueryOptions());
            $this->assertNull($entity->queryLimit()->getFields());
            $this->assertNull($entity->queryLimit()->getHideFields());
        });
    }

    public function testExactMultipleStopsAfterTheEmptyPage(): void
    {
        $this->withDatabase([
            [['id' => 1], ['id' => 2]],
            [],
        ], function (array &$queries, array &$rawQueries): void {
            $ids = [];
            (new ChunkEntity())->chunk(function (ChunkEntity $item) use (&$ids): void {
                $ids[] = $item->id;
            }, 2);

            $this->assertSame([1, 2], $ids);
            $this->assertCount(2, $queries);
            $this->assertSame([], $rawQueries);
        });
    }

    public function testEmptyResultDoesNotCallTheCallback(): void
    {
        $this->withDatabase([[]], function (array &$queries, array &$rawQueries): void {
            $called = false;
            (new ChunkEntity())->chunk(function () use (&$called): void { $called = true; }, 2);

            $this->assertFalse($called);
            $this->assertCount(1, $queries);
            $this->assertSame([], $rawQueries);
        });
    }

    public function testCallbackFailureClearsTheOriginalQueryState(): void
    {
        $this->withDatabase([[['id' => 1]]], function (array &$queries, array &$rawQueries): void {
            $entity = new ChunkEntity();
            $entity->queryLimit()->where('status', 1)->orderBy('id', 'ASC')->fields(['id']);
            $failure = new \RuntimeException('stop chunk');
            try {
                $entity->chunk(static function () use ($failure): void { throw $failure; }, 2);
                $this->fail('The callback exception must propagate.');
            } catch (\RuntimeException $caught) {
                $this->assertSame($failure, $caught);
            }

            $this->assertCount(1, $queries);
            $this->assertSame([], $rawQueries);
            $this->assertNull($entity->queryLimit()->getFields());
            $next = $entity->queryLimit()->__getQueryBuilder()->get($entity->tableName());
            $this->assertSame([], $next->getLastBindParams());
            $this->assertStringNotContainsString('WHERE', $next->getLastPrepareQuery());
            $this->assertStringNotContainsString('ORDER BY', $next->getLastPrepareQuery());
            $this->assertStringNotContainsString('LIMIT', $next->getLastPrepareQuery());
        });
    }

    private function withDatabase(array $pages, callable $test): void
    {
        $queries = [];
        $rawQueries = [];
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'rawQuery', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $client->expects($this->exactly(count($pages)))->method('query')
            ->willReturnCallback(static function (QueryBuilder $query) use (&$pages, &$queries): array {
                $queries[] = [$query->getLastPrepareQuery(), $query->getLastBindParams(), $query->getLastQueryOptions()];
                return array_shift($pages);
            });
        $client->method('rawQuery')->willReturnCallback(static function (string $sql) use (&$rawQueries): array {
            $rawQueries[] = $sql;
            return [['count' => 3]];
        });
        $client->method('getLastAffectRows')->willReturn(0);
        $client->method('getLastInsertId')->willReturn(0);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db,
            [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $test($queries, $rawQueries);
        } finally {
            $instance->setValue(null, $original);
        }
    }
}

class ChunkEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property]
    public ?string $name;

    #[Property]
    public ?int $status;

    public function tableName(): string { return 'chunk_test'; }
}
