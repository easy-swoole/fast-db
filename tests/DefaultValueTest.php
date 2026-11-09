<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertDateTime;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Utility\ReflectionCache;
use PHPUnit\Framework\TestCase;

final class DefaultValueTest extends TestCase
{
    public function testConvertedDefaultsBelongToEachEntity(): void
    {
        $first = new MutableDefaultEntity();
        $first->tags->append('first request');
        $second = new MutableDefaultEntity();

        $this->assertNotSame($first->tags, $second->tags);
        $this->assertSame([], $second->tags->toArray());
        $this->assertTrue($second->update());
        $defaults = ReflectionCache::getInstance()->parseEntity(MutableDefaultEntity::class)->allProperties();
        $this->assertSame([], $defaults['tags']->defaultValue->toArray());
    }

    public function testOrdinaryObjectDefaultsAlsoBelongToEachEntity(): void
    {
        $first = new MutableDefaultEntity();
        $first->metadata->request = 'first';
        $second = new MutableDefaultEntity();

        $this->assertNotSame($first->metadata, $second->metadata);
        $this->assertSame([], get_object_vars($second->metadata));
    }

    public function testDateTimeDefaultPreservesTimezoneAndMicroseconds(): void
    {
        $first = new DateTimeDefaultEntity();
        $first->createdAt->modify('+1 day');
        $second = new DateTimeDefaultEntity();

        $this->assertNotSame($first->createdAt, $second->createdAt);
        $this->assertSame('Asia/Shanghai', $second->createdAt->getTimezone()->getName());
        $this->assertSame('2026-11-09 12:34:56.123456', $second->createdAt->format('Y-m-d H:i:s.u'));
        $this->assertTrue($second->update());
    }
}

class MutableDefaultEntity extends AbstractEntity
{
    #[Property(defaultValue: new ConvertList([]), convertObject: ConvertList::class)]
    public ConvertList $tags;

    #[Property(defaultValue: new \stdClass())]
    public \stdClass $metadata;

    public function tableName(): string
    {
        return 'mutable_defaults';
    }
}

class DateTimeDefaultEntity extends AbstractEntity
{
    #[Property(
        defaultValue: new ConvertDateTime('2026-11-09 12:34:56.123456', new \DateTimeZone('Asia/Shanghai')),
        convertObject: ConvertDateTime::class
    )]
    public ConvertDateTime $createdAt;

    public function tableName(): string
    {
        return 'datetime_defaults';
    }
}
