<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class NullableConvertTest extends TestCase
{
    public function testSetDataClearsNullableConvertedValue(): void
    {
        $entity = new NullableConvertEntity(['id' => 1, 'tags' => '[1]']);
        $this->assertSame([1], $entity->tags->toArray());
        $this->assertSame($entity, $entity->setData(['tags' => null]));
        $this->assertNull($entity->tags);
        $this->assertNull($entity->toArray()['tags']);
    }

    public function testClearedValueIsWrittenAsSqlNull(): void
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()->onlyMethods(['getLastAffectRows'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $result = new QueryResult(microtime(true));
        $result->setConnection($connection);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->once())->method('query')
            ->with($this->callback(function (QueryBuilder $query): bool {
                $this->assertMatchesRegularExpression('/`tags`\s*=\s*\?/', $query->getLastPrepareQuery());
                $params = $query->getLastBindParams();
                $this->assertArrayHasKey(0, $params);
                $this->assertNull($params[0]);
                return true;
            }))->willReturn($result);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $entity = new NullableConvertEntity(['id' => 1, 'tags' => '[1]']);
            $entity->setData(['tags' => null]);
            $this->assertTrue($entity->update());
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testMergeCompareAcceptsNullAsUnchangedBaseline(): void
    {
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->never())->method('query');
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $entity = new NullableConvertEntity(['id' => 1, 'tags' => '[1]']);
            $entity->setData(['tags' => null], true);
            $this->assertNull($entity->tags);
            $this->assertTrue($entity->update());
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testNonNullableValueStillUsesConverter(): void
    {
        $entity = new NullableConvertEntity(['requiredTags' => '[1]']);
        $entity->setData(['requiredTags' => null]);
        $this->assertInstanceOf(ConvertList::class, $entity->requiredTags);
        $this->assertSame([], $entity->requiredTags->toArray());
    }
}

class NullableConvertEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property(convertObject: ConvertList::class)]
    public ?ConvertList $tags;

    #[Property(convertObject: ConvertList::class)]
    public ConvertList $requiredTags;

    public function tableName(): string
    {
        return 'nullable_convert_test';
    }
}
