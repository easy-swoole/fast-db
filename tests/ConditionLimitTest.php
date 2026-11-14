<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConditionLimitTest extends TestCase
{
    public static function conditions(): iterable
    {
        foreach (['findRecord', 'findAll', 'fastUpdate', 'fastDelete'] as $operation) {
            yield "$operation scalar" => [$operation, '01', ['01'], '`id` = ?'];
            yield "$operation string zero" => [$operation, '0', ['0'], '`id` = ?'];
            if ($operation !== 'findAll') {
                yield "$operation integer zero" => [$operation, 0, [0], '`id` = ?'];
            }
            foreach (['is_object', 'trim', ConditionCallbacks::class . '::filter'] as $key) {
                yield "$operation string key $key" => [$operation, $key, [$key], '`id` = ?'];
            }
            yield "$operation static callback" => [$operation, ConditionCallbacks::filter(...), [10], '`value` >= ?'];
            yield "$operation instance callback" => [$operation, (new ConditionCallbacks())->filterInstance(...), [10], '`value` >= ?'];
            yield "$operation invokable" => [$operation, new ConditionCallbacks(), [10], '`value` >= ?'];
            yield "$operation converted string callback" => [$operation, \Closure::fromCallable(ConditionCallbacks::class . '::filter'), [10], '`value` >= ?'];
            yield "$operation equality" => [$operation, ['value' => 10], [10], '`value` = ?'];
            yield "$operation operator" => [$operation, ['value' => [10, '>']], [10], '`value` > ?'];
            yield "$operation IN" => [$operation, ['id' => [['01', 'a'], 'IN']], ['01', 'a'], '`id` IN'];
            yield "$operation callback" => [$operation, static function (QueryBuilder $query): void {
                $query->where('value', 10, '>=');
            }, [10], '`value` >= ?'];
            yield "$operation comma in literal key" => [$operation, '01,a', ['01,a'], '`id` = ?'];
        }
    }

    #[DataProvider('conditions')]
    public function testAllShortcutsUseTheSameConditionRules(string $operation, mixed $limit, array $parameters, string $condition): void
    {
        $write = str_starts_with($operation, 'fast');
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $client->method('getLastAffectRows')->willReturn(1);
        $client->method('getLastInsertId')->willReturn(0);
        $client->expects($this->once())->method('query')->willReturnCallback(
            function (QueryBuilder $query) use ($operation, $parameters, $condition, $write): bool|array {
                $this->assertSame($operation === 'fastUpdate' ? [99, ...$parameters] : $parameters, $query->getLastBindParams());
                $this->assertStringContainsString($condition, $query->getLastPrepareQuery());
                return $write ? true : [];
            });
        $db = new FastDb();
        $current = new \ReflectionProperty(FastDb::class, 'currentConnection');
        $current->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $singleton = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $singleton->getValue();
        $singleton->setValue(null, $db);
        try {
            $result = $operation === 'fastUpdate'
                ? ConditionRecord::fastUpdate($limit, ['value' => 99])
                : ConditionRecord::$operation($limit);
            $this->assertSame($write ? 1 : ($operation === 'findRecord' ? null : []), $result);
        } finally {
            $current->setValue($db, []);
            $singleton->setValue(null, $original);
        }
    }

    public function testArrayWithCallableShapeIsRejectedAsAnInvalidCondition(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Query conditions require column names as array keys');
        ConditionRecord::findAll([ConditionCallbacks::class, 'filter']);
    }

    public function testFastUpdateRejectsEmptyConditionsBeforeQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fastUpdate requires a non-empty condition or primary key');
        ConditionRecord::fastUpdate([], ['value' => 99]);
    }

    public function testArrayConditionRequiresAnExplicitOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Array conditions require');
        ConditionRecord::findAll(['value' => []]);
    }
}

class ConditionRecord extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public string $id;
    #[Property] public int $value;
    public function tableName(): string { return 'condition_records'; }
}

class ConditionCallbacks
{
    public static function filter(QueryBuilder $query): void { $query->where('value', 10, '>='); }
    public function filterInstance(QueryBuilder $query): void { self::filter($query); }
    public function __invoke(QueryBuilder $query): void { self::filter($query); }
}
