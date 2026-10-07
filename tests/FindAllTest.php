<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FindAllTest extends TestCase
{
    /** @dataProvider primaryKeyQueries */
    public function testPrimaryKeyQuery(?string $primaryKeys, string $expectedCondition): void
    {
        $result = new QueryResult(microtime(true));
        $result->setResult([]);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->once())->method('query')
            ->with($this->callback(function (QueryBuilder $query) use ($expectedCondition): bool {
                $sql = $query->getLastQuery();
                if ($expectedCondition === '') {
                    $this->assertStringNotContainsString('WHERE', $sql);
                } else {
                    $this->assertStringContainsString($expectedCondition, $sql);
                }
                return true;
            }))->willReturn($result);

        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $this->assertSame([], User::findAll($primaryKeys, null, true));
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public static function primaryKeyQueries(): array
    {
        return [
            'single primary key' => ['1', "WHERE  `id` = '1'"],
            'zero primary key' => ['0', "WHERE  `id` = '0'"],
            'string primary key' => ['user-1', "WHERE  `id` = 'user-1'"],
            'multiple primary keys' => ['1,2', 'WHERE  `id` IN ( 1, 2 )'],
            'unfiltered query' => [null, ''],
        ];
    }
}
