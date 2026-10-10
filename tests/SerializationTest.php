<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use PHPUnit\Framework\TestCase;

final class SerializationTest extends TestCase
{
    public function testPaginatedListKeepsHiddenFieldsInJson(): void
    {
        $rows = new QueryResult(microtime(true));
        $rows->setResult([['id' => 1, 'name' => 'secret', 'email' => 'public@example.com']]);
        $count = new QueryResult(microtime(true));
        $count->setResult([['count' => 1]]);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query', 'rawQuery'])->getMock();
        $db->expects($this->once())->method('query')->willReturn($rows);
        $db->expects($this->once())->method('rawQuery')->willReturn($count);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $user = new User();
            $user->queryLimit()->hideFields('name')->persistFieldLimit()
                ->where('status', 0)->page(1, true, 10)->orderBy('id', 'DESC');
            $result = $user->all();
            for ($i = 0; $i < 2; $i++) {
                $data = json_decode(json_encode([
                    'list' => $result->list(),
                    'total' => $result->totalCount(),
                    'page' => 1,
                    'pageSize' => 10,
                ], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('name', $data['list'][0]);
                $this->assertSame('public@example.com', $data['list'][0]['email']);
                $this->assertSame(1, $data['total']);
            }
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testHiddenFieldsRemainHiddenAcrossJsonSerializations(): void
    {
        $user = new User(['id' => 1, 'name' => 'secret', 'email' => 'private@example.com']);
        $user->queryLimit()->hideFields(['name', 'email']);

        for ($i = 0; $i < 3; $i++) {
            $data = json_decode(json_encode($user, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(1, $data['id']);
            $this->assertArrayNotHasKey('name', $data);
            $this->assertArrayNotHasKey('email', $data);
        }
    }

    public function testFieldSelectionSurvivesMixedSerializationCalls(): void
    {
        $user = new User(['id' => 1, 'name' => 'secret', 'score' => 10]);
        $user->queryLimit()->fields(['id', 'name'])->hideFields('name')->persistFieldLimit();

        $this->assertSame(['id' => 1], $user->toArray());
        $this->assertSame(['id' => 1], json_decode(json_encode($user), true));
        $this->assertSame(['id' => 1], $user->toArray(true));
        $this->assertTrue($user->queryLimit()->isPersistFieldLimit());
    }

    public function testSerializationStillClearsSqlConditions(): void
    {
        $user = new User(['id' => 1, 'name' => 'secret']);
        $user->queryLimit()->where('id', 1)->hideFields('name');
        $user->toArray();

        $query = $user->queryLimit()->__getQueryBuilder();
        $query->get($user->tableName());
        $this->assertStringNotContainsString('WHERE', $query->getLastQuery());
        $this->assertArrayNotHasKey('name', $user->toArray());
    }

    public function testHiddenFieldsCanBeExplicitlyCleared(): void
    {
        $user = new User(['id' => 1, 'name' => 'secret']);
        $user->queryLimit()->hideFields('name');
        $this->assertArrayNotHasKey('name', $user->toArray());
        $user->queryLimit()->hideFields([]);
        $this->assertSame('secret', $user->toArray()['name']);
    }
}
