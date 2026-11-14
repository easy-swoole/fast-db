<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class UpdateConversionTest extends TestCase
{
    public function testJsonConversionAndComparisonBaseline(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $connection->method('getLastInsertId')->willReturn(0);
        $queries = [];
        $connection->expects($this->exactly(2))->method('query')
            ->willReturnCallback(function (QueryBuilder $query) use (&$queries): bool {
                $queries[] = $query->getLastBindParams();
                return true;
            });
        $db = $this->database($connection);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $entity = new UpdateConversionEntity(['id' => 1, 'payload' => '[1]']);
            $this->assertTrue($entity->update()); // 从数据库加载的值没有变化。
            $entity->setData(['payload' => [2]]);
            $this->assertTrue($entity->update());
            $this->assertSame([2], $entity->payload->toArray());
            $this->assertTrue($entity->update()); // 更新成功后，将当前值作为新的比较基准。
            $entity->setData(['payload' => [1]]);
            $this->assertTrue($entity->update());
            $this->assertSame(['[2]', 1], $queries[0]);
            $this->assertSame(['[1]', 1], $queries[1]);
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testNullableConvertedFieldWritesSqlNull(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['query', 'getLastAffectRows', 'getLastInsertId'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $connection->method('getLastInsertId')->willReturn(0);
        $connection->expects($this->once())->method('query')
            ->with($this->callback(function (QueryBuilder $query): bool {
                $this->assertSame([null, 1], $query->getLastBindParams());
                return true;
            }))->willReturn(true);
        $db = $this->database($connection);
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $entity = new UpdateConversionEntity(['id' => 1, 'payload' => '[1]']);
            $entity->payload = null;
            $this->assertTrue($entity->update());
            $this->assertNull($entity->payload);
        } finally {
            $instance->setValue(null, $original);
        }
    }

    private function database(Connection $client): FastDb
    {
        $db = new FastDb();
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        return $db;
    }
}

class UpdateConversionEntity extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;

    #[Property(convertObject: ConvertList::class)]
    public ?ConvertList $payload;

    public function tableName(): string
    {
        return 'codex_update_conversion_20261007';
    }
}
