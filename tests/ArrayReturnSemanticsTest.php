<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Swoole\Coroutine;

final class ArrayReturnSemanticsTest extends TestCase
{
    private function all(array $rows, ?array $hidden = null): ListResult
    {
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $client->method('getLastAffectRows')->willReturn(0);
        $client->method('getLastInsertId')->willReturn(0);
        $client->expects($this->once())->method('query')->willReturn($rows);
        $db = new FastDb();
        (new ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db, [Coroutine::getCid() => ['default' => $client]]);
        $singleton = new ReflectionProperty(FastDb::class, 'instance');
        $original = $singleton->getValue();
        $singleton->setValue(null, $db);
        try {
            $entity = new ArrayReturnRecord();
            $entity->queryLimit()->returnAsArray();
            if ($hidden !== null) { $entity->queryLimit()->hideFields($hidden); }
            return $entity->all();
        } finally {
            $singleton->setValue(null, $original);
        }
    }

    public function testArrayResultPreservesTheDriverRowOrder(): void
    {
        $rows = [['id' => 2], ['id' => 1], ['id' => 3]];
        $result = $this->all($rows);

        $this->assertSame($rows, $result->list());
        $this->assertSame($rows[0], $result->getIterator()->current());
        $this->assertSame([], $this->all([])->list());
    }

    public function testNestedIterationUsesIndependentIteratorsAndPreservesKeys(): void
    {
        $result = new ListResult([10 => ['id' => 1], 'second' => ['id' => 2], 30 => ['id' => 3]]);
        $pairs = [];
        foreach ($result as $outerKey => $outer) {
            foreach ($result as $innerKey => $inner) {
                $pairs[$outerKey][$innerKey] = $outer['id'] + $inner['id'];
            }
        }
        $this->assertSame([
            10 => [10 => 2, 'second' => 3, 30 => 4],
            'second' => [10 => 3, 'second' => 4, 30 => 5],
            30 => [10 => 4, 'second' => 5, 30 => 6],
        ], $pairs);
    }

    public function testHiddenFieldsAreRemovedWithoutChangingSourceRows(): void
    {
        $rows = [['id' => 1, 'secret' => 'private'], ['id' => 2, 'secret' => null]];
        $this->assertSame([['id' => 1], ['id' => 2]], $this->all($rows, ['secret'])->list());
        $this->assertSame('private', $rows[0]['secret']);
        $this->assertArrayHasKey('secret', $rows[1]);
        $this->assertSame([], $this->all([])->list());
    }

    public function testSerializationKeepsConversionsAndNullFilteringWithNoHiddenFields(): void
    {
        $entity = new ArrayReturnConvertedRecord(['payload' => [1]]);
        $this->assertSame(['payload' => '[1]', 'name' => null], $entity->toArray());
        $entity->queryLimit()->hideFields([]);
        $this->assertSame(['payload' => '[1]'], $entity->toArray(true));
        $entity->queryLimit()->hideFields('payload');
        $this->assertSame(['name' => null], $entity->toArray());
    }
}

class ArrayReturnRecord extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property] public ?string $secret;
    public function tableName(): string { return 'array_return_records'; }
}

class ArrayReturnConvertedRecord extends AbstractEntity
{
    #[Property(convertObject: ConvertList::class)]
    public ?ConvertList $payload;
    #[Property] public ?string $name;
    public function tableName(): string { return 'array_return_converted_records'; }
}
