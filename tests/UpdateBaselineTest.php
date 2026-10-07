<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
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
        $connection = $this->createStub(Connection::class);
        $connection->method('getLastAffectRows')->willReturnOnConsecutiveCalls(...$affectedRows);
        $result = new QueryResult(microtime(true));
        $result->setConnection($connection);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->exactly($count))->method('query')
            ->willReturnCallback(function (QueryBuilder $query) use ($result): QueryResult {
                $this->queries[] = $query->getLastQuery();
                return $result;
            });
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

    public function testFailedUpdateDoesNotAcceptChanges(): void
    {
        $this->mockUpdates(2, [0, 1]);
        $user = new User(['id' => 1, 'name' => 'A']);
        $user->name = 'B';
        $this->assertFalse($user->update());
        $this->assertTrue($user->update());
        $this->assertSame($this->queries[0], $this->queries[1]);
    }
}
