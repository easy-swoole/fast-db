<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Tests\Model\User;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class UpdateFieldsTest extends TestCase
{
    /** @dataProvider fieldSelections */
    public function testUpdateIncludesOnlySelectedChangedFields(?array $fields, array $expectedFields): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['getLastAffectRows'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $result = new QueryResult(microtime(true));
        $result->setConnection($connection);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        if ($expectedFields === []) {
            $db->expects($this->never())->method('query');
        } else {
            $db->expects($this->once())->method('query')
                ->with($this->callback(function (QueryBuilder $query) use ($expectedFields): bool {
                    $sql = $query->getLastPrepareQuery();
                    foreach (['name', 'score', 'status'] as $field) {
                        if (in_array($field, $expectedFields, true)) {
                            $this->assertStringContainsString("`{$field}` = ?", $sql);
                        } else {
                            $this->assertStringNotContainsString("`{$field}` = ?", $sql);
                        }
                    }
                    $this->assertMatchesRegularExpression('/WHERE\s+`id`\s*=\s*\?/', $sql);
                    return true;
                }))->willReturn($result);
        }

        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $user = new User(['id' => 1, 'name' => 'old', 'score' => 1, 'status' => 1]);
            $user->setData(['name' => 'new', 'score' => 2]);
            $user->queryLimit()->fields($fields);
            $this->assertTrue($user->update());
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public static function fieldSelections(): array
    {
        return [
            'one selected field' => [['name'], ['name']],
            'multiple selected fields' => [['name', 'score'], ['name', 'score']],
            'unchanged selected field' => [['status'], []],
            'null leaves fields unrestricted' => [null, ['name', 'score']],
            'empty list leaves fields unrestricted' => [[], ['name', 'score']],
        ];
    }
}
