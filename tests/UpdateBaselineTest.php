<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class UpdateBaselineTest extends TestCase
{
    private array $queries = [];
    private \ReflectionProperty $instance;
    private mixed $original;

    protected function setUp(): void
    {
        $this->instance = new \ReflectionProperty(FastDb::class, 'instance');
        $this->original = $this->instance->getValue();
    }

    protected function tearDown(): void
    {
        $this->instance->setValue(null, $this->original);
    }

    private function mockUpdates(int $count, array $affectedRows = [1]): void
    {
        $affected = 0;
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $client->expects($this->exactly($count))->method('query')
            ->willReturnCallback(function (QueryBuilder $query) use (&$affected, &$affectedRows): bool {
                $affected = count($affectedRows) > 1 ? array_shift($affectedRows) : $affectedRows[0];
                $this->queries[] = $query->getLastQuery();
                return true;
            });
        $client->method('getLastAffectRows')->willReturnCallback(static function () use (&$affected): int { return $affected; });
        $client->method('getLastInsertId')->willReturn(0);
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $this->instance->setValue(null, $db);
    }

    public function testUpdatingBackToOriginalValueExecutesAnotherUpdate(): void
    {
        $this->mockUpdates(2, [1, 1]);
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->name = 'B';
        $this->assertTrue($user->update());
        $user->name = 'A';
        $this->assertTrue($user->update());
        $this->assertStringContainsString("`name` = 'B'", $this->queries[0]);
        $this->assertStringContainsString("`name` = 'A'", $this->queries[1]);
    }

    public function testRepeatedUpdateWithoutChangesDoesNotExecuteSql(): void
    {
        $this->mockUpdates(1);
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->name = 'B';
        $this->assertTrue($user->update());
        $this->assertTrue($user->update());
    }

    public function testExcludedChangesRemainPending(): void
    {
        $this->mockUpdates(2, [1, 1]);
        $user = new User(['id' => 1, 'name' => 'A', 'score' => 1]);
        $user->name = 'B';
        $user->score = 2;
        $user->queryLimit()->fields(['name']);
        $this->assertTrue($user->update());
        $this->assertTrue($user->update());
        $this->assertStringNotContainsString('`score` =', $this->queries[0]);
        $this->assertStringContainsString('`score` = 2', $this->queries[1]);
        $this->assertStringNotContainsString('`name` =', $this->queries[1]);
    }

    public function testBuilderFieldSelectionLeavesOtherChangesPending(): void
    {
        $this->mockUpdates(2);
        $user = new User(['id' => 1, 'name' => 'A', 'score' => 1]);
        $user->setData(['name' => 'B', 'score' => 2]);
        $user->queryLimit()->func(static fn (QueryBuilder $builder) => $builder->fields(['name']));
        $this->assertTrue($user->update());
        $this->assertSame(['score' => 2], $user->getChanges());
        $this->assertStringContainsString("`name` = 'B'", $this->queries[0]);
        $this->assertStringNotContainsString('`score` =', $this->queries[0]);

        $this->assertTrue($user->update());
        $this->assertSame([], $user->getChanges());
        $this->assertStringContainsString('`score` = 2', $this->queries[1]);
    }

    public function testBuilderFieldsWithNoChangedColumnSkipSql(): void
    {
        $this->mockUpdates(0);
        $user = new User(['id' => 1, 'name' => 'A', 'score' => 1]);
        $user->name = 'B';
        $user->queryLimit()->func(static fn (QueryBuilder $builder) => $builder->fields(['score']));
        $this->assertTrue($user->update());
        $this->assertSame(['name' => 'B'], $user->getChanges());
    }

    public function testFailedUpdateDoesNotAcceptChanges(): void
    {
        $this->mockUpdates(2, [0, 1]);
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->name = 'B';
        $this->assertFalse($user->update());
        $this->assertTrue($user->hasChange('name'));
        $this->assertSame(['name'=>'B'], $user->getChanges());
        $this->assertTrue($user->update());
        $this->assertFalse($user->hasChange());
        $this->assertSame([], $user->getChanges());
        $this->assertSame($this->queries[0], $this->queries[1]);
    }
}
