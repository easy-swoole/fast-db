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
    public function testDuplicatePropertyAttributeFailsAtReflection(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must not be repeated');
        new class extends AbstractEntity {
            #[Property(defaultValue: 1)]
            #[Property(defaultValue: 2)]
            public int $value;

            public function tableName(): string { return 'unused'; }
        };
    }

    public function testConvertedDefaultsBelongToEachEntity(): void
    {
        $first = new MutableDefaultEntity();
        $first->tags->append('first request');
        $second = new MutableDefaultEntity();

        $this->assertNotSame($first->tags, $second->tags);
        $this->assertSame([], $second->tags->toArray());
        $this->assertFalse($second->hasChange());
        $defaults = ReflectionCache::getInstance()->parseEntity(MutableDefaultEntity::class)->allProperties();
        $this->assertSame([], $defaults['tags']->getDefaultValue()->toArray());
    }

    public function testCachedPropertyMetadataCannotBeChangedThroughItsReaders(): void
    {
        $cache = ReflectionCache::getInstance();
        $metadata = $cache->parseEntity(MutableDefaultEntity::class);
        $this->assertSame($metadata, $cache->parseEntity(MutableDefaultEntity::class));
        $property = $metadata->allProperties()['tags'];
        $property->getDefaultValue()->append('changed through metadata');
        $this->assertSame([], (new MutableDefaultEntity())->tags->toArray());

        $this->expectException(\Error::class);
        $property->allowNull = true;
    }

    public function testObjectDefaultsInsideArraysAreCopied(): void
    {
        $metadata = ReflectionCache::getInstance()->parseEntity(MutableDefaultEntity::class);
        $defaults = $metadata->allProperties()['nested']->getDefaultValue();
        $defaults['outer']['object']->changed = true;

        $first = new MutableDefaultEntity();
        $first->nested['outer']['object']->changed = true;
        $second = new MutableDefaultEntity();

        $this->assertSame([], get_object_vars($second->nested['outer']['object']));
        $this->assertNotSame($first->nested['outer']['object'], $second->nested['outer']['object']);
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
        $this->assertFalse($second->hasChange());
    }

    public function testDefaultsUseTheSameComparisonAsLaterChanges(): void
    {
        $entity = new ComparisonDefaultEntity();
        $this->assertSame(0, $entity->score);
        $this->assertFalse($entity->hasChange());
        $this->assertSame([], $entity->getChanges());

        $entity->score = 1;
        $entity->labels->append('changed');
        $this->assertSame(['score' => 1, 'labels' => $entity->labels], $entity->getChanges());
    }
}

class MutableDefaultEntity extends AbstractEntity
{
    #[Property(defaultValue: new ConvertList([]), convertObject: ConvertList::class)]
    public ConvertList $tags;

    #[Property(defaultValue: new \stdClass())]
    public \stdClass $metadata;

    #[Property(defaultValue: ['outer' => ['object' => new \stdClass()]])]
    public array $nested;

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

class ComparisonDefaultEntity extends AbstractEntity
{
    #[Property(defaultValue: '0')]
    public int $score;

    #[Property(defaultValue: new ConvertList([]))]
    public ConvertList $labels;

    public function tableName(): string { return 'comparison_defaults'; }
}
