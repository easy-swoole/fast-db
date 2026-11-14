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
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        $singleton = new \ReflectionProperty(FastDb::class, 'instance');
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

    public function testPackedRowsStartAtTheFirstRowRegardlessOfTheSourcePointer(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];
        next($rows);
        $result = $this->all($rows);

        $this->assertSame(['id' => 1], $result->current());
        $this->assertSame(0, $result->key());
        $this->assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $result->list());
        $this->assertSame(1, key($rows));
    }

    public function testSparseAndNamedRowsAreRenumberedInTheirOriginalOrder(): void
    {
        $rows = [10 => ['id' => 2], 'custom' => ['id' => 1], 30 => ['id' => 3]];
        $result = $this->all($rows, []);

        $this->assertSame([['id' => 2], ['id' => 1], ['id' => 3]], $result->list());
        $this->assertSame(['id' => 2], $result->current());
        $this->assertSame([10, 'custom', 30], array_keys($rows));
    }

    public function testRowReferencesAreDetachedFromTheReturnedList(): void
    {
        $row = ['id' => 1];
        $rows = [&$row, ['id' => 2]];
        $result = $this->all($rows);
        $row = ['id' => 99];

        $this->assertSame([['id' => 1], ['id' => 2]], $result->list());
        $result[1] = ['id' => 3];
        $this->assertSame(['id' => 2], $rows[1]);
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
