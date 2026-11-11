<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testFalseStopsInsideAFullPageWithoutCallingRemainingRowsOrReadingAnotherPage(): void
    {
        $this->withDatabase([[['id' => 1], ['id' => 2], ['id' => 3]]], function (array &$queries, array &$rawQueries): void {
            $entity = new ChunkEntity();
            $entity->queryLimit()->where('status', 1)->orderBy('id', 'ASC')
                ->fields(['id'])->hideFields('name')->persistFieldLimit();
            $seen = [];
            $result = $entity->chunk(static function (ChunkEntity $item) use (&$seen): bool {
                $seen[] = $item->id;
                return $item->id !== 2;
            }, 3);

            $this->assertNull($result, 'The existing chunk() return contract remains unchanged.');
            $this->assertSame([1, 2], $seen);
            $this->assertCount(1, $queries);
            $this->assertSame([], $rawQueries);
            $this->assertNull($entity->queryLimit()->getFields());
            $this->assertNull($entity->queryLimit()->getHideFields());
            $this->assertFalse($entity->queryLimit()->isPersistFieldLimit());
            $next = $entity->queryLimit()->__getQueryBuilder()->get($entity->tableName());
            $this->assertSame([], $next->getLastBindParams());
            $this->assertSame([], $next->getLastQueryOptions());
            $this->assertStringNotContainsString('WHERE', $next->getLastPrepareQuery());
            $this->assertStringNotContainsString('ORDER BY', $next->getLastPrepareQuery());
            $this->assertStringNotContainsString('LIMIT', $next->getLastPrepareQuery());
        });
    }

    public function testZeroReturnValueDoesNotStopDeliveryAndChunkStillReturnsNull(): void
    {
        $this->withDatabase([
            [['id' => 1], ['id' => 2]],
            [['id' => 3]],
        ], function (array &$queries, array &$rawQueries): void {
            $seen = [];
            $result = (new ChunkEntity())->chunk(static function (ChunkEntity $item) use (&$seen): int {
                $seen[] = $item->id;
                return 0;
            }, 2);

            $this->assertNull($result);
            $this->assertSame([1, 2, 3], $seen);
            $this->assertCount(2, $queries);
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

    public static function filteredPageModes(): array
    {
        $cases = [];
        foreach (['global','entity'] as $mode) {
            foreach ([0,1] as $keep) {
                foreach ([false,true] as $arrays) { $cases[] = [$mode,$keep,$arrays]; }
            }
        }
        return $cases;
    }

    #[DataProvider('filteredPageModes')]
    public function testFilteredFirstPageDoesNotStopOffsetTraversal(string $mode, int $keep, bool $arrays): void
    {
        $this->withDatabase([[['id'=>1],['id'=>2]], [['id'=>3],['id'=>4]], [['id'=>5]]],
            function (array &$queries, array &$rawQueries, FastDb $db) use ($mode,$keep,$arrays): void {
                $entity = new ChunkEntity();
                $entity->queryLimit()->orderBy('id','ASC');
                if ($arrays) { $entity->queryLimit()->returnAsArray(); }
                $first = true;
                $observer = static function (QueryResult $result) use (&$first,$keep): void {
                    if ($first) {
                        $first = false;
                        $result->setResult(array_slice($result->getResult(),0,$keep));
                    }
                };
                if ($mode === 'global') { $db->setOnQuery($observer); }
                else { $entity->setOnQuery($observer); }
                $seen = [];
                $entity->chunk(static function ($row) use (&$seen,$arrays): void { $seen[] = $arrays ? $row['id'] : $row->id; },2);
                $this->assertSame($keep === 0 ? [3,4,5] : [1,3,4,5], $seen);
                $this->assertCount(3, $queries);
                $this->assertSame([], $rawQueries);
            });
    }

    public static function observerModes(): array { return [['global'],['entity']]; }

    #[DataProvider('observerModes')]
    public function testNestedAllCannotReplaceTheOuterChunkPageCount(string $mode): void
    {
        $this->withDatabase([[['id'=>1],['id'=>2]], [['id'=>10]], [['id'=>3]]],
            function (array &$queries, array &$rawQueries, FastDb $db) use ($mode): void {
                $entity = new ChunkEntity();
                $entity->queryLimit()->orderBy('id','ASC');
                $entered = false;
                $observer = function () use (&$entered,$entity): void {
                    if ($entered) { return; }
                    $entered = true;
                    $this->assertSame(10, $entity->all()->first()->id);
                };
                if ($mode === 'global') { $db->setOnQuery($observer); }
                else { $entity->setOnQuery($observer); }
                $seen = [];
                $entity->chunk(static function (ChunkEntity $row) use (&$seen): void { $seen[] = $row->id; },2);
                $this->assertSame([1,2,3], $seen);
                $this->assertCount(3, $queries);
                $this->assertSame([], $rawQueries);
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
            $test($queries, $rawQueries, $db);
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
