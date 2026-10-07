<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class UpdateConversionTest extends TestCase
{
    public function testJsonConversionAndComparisonBaseline(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['getLastAffectRows'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $result = new QueryResult(microtime(true));
        $result->setConnection($connection);
        $queries = [];
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->exactly(2))->method('query')
            ->willReturnCallback(function (QueryBuilder $query) use (&$queries, $result): QueryResult {
                $queries[] = $query->getLastBindParams();
                return $result;
            });
        $instance = new \ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $instance->setValue(null, $db);
        try {
            $entity = new UpdateConversionEntity(['id' => 1, 'payload' => '[1]']);
            $this->assertTrue($entity->update()); // Loaded value is unchanged.
            $entity->payload = [2];
            $this->assertTrue($entity->update());
            $this->assertSame([2], $entity->payload);
            $this->assertTrue($entity->update()); // Successful update becomes baseline.
            $entity->payload = [1];
            $this->assertTrue($entity->update());
            $this->assertSame(['[2]', 1], $queries[0]);
            $this->assertSame(['[1]', 1], $queries[1]);
        } finally {
            $instance->setValue(null, $original);
        }
    }

    public function testCallbackReceivesCurrentEntityAndNull(): void
    {
        $connection = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['getLastAffectRows'])->getMock();
        $connection->method('getLastAffectRows')->willReturn(1);
        $result = new QueryResult(microtime(true));
        $result->setConnection($connection);
        $db = $this->getMockBuilder(FastDb::class)->onlyMethods(['query'])->getMock();
        $db->expects($this->once())->method('query')
            ->with($this->callback(function (QueryBuilder $query): bool {
                $this->assertSame([null, 1], $query->getLastBindParams());
                return true;
            }))->willReturn($result);
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
}

class UpdateConversionEntity extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property(
        assignCall: new Call([UpdateConversionEntity::class, 'decode']),
        toValue: new Call([UpdateConversionEntity::class, 'encode'],
            [Call::PARAM_PROPERTY_VALUE, Call::PARAM_CURRENT_ENTITY])
    )]
    public ?array $payload;

    public static function decode(?string $value): ?array
    {
        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function encode(?array $value, UpdateConversionEntity $entity): ?string
    {
        if ($entity->id !== 1) {
            throw new \RuntimeException('Unexpected current entity');
        }
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function tableName(): string
    {
        return 'codex_update_conversion_20261007';
    }
}
