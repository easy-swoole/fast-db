<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Beans\ArrayList;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Tests\Model\User;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class RefactorBoundaryTest extends TestCase
{
    public function testSparseCollectionUsesKeysForIdentityRemoval(): void
    {
        $a = new User(['id' => 1]); $b = new User(['id' => 2]); $c = new User(['id' => 3]);
        $list = new ArrayList([$a, $b, $c]); unset($list[0]);
        $this->assertSame($b, $list->first());
        $list->remove($b);
        $this->assertSame([$c], $list->list());
    }

    public function testClonedEntityHasIndependentStateAndQueryOwner(): void
    {
        $a = new User(['id' => 1, 'name' => 'A']); $a->queryLimit()->fields(['name']);
        $b = clone $a;
        $this->assertSame($b, $b->queryLimit()->returnEntity());
        $b->setData(['name' => 'B'], true);
        $this->assertSame('A', $a->name);
        $a->queryLimit()->fields(['id']);
        $this->assertSame(['name'], $b->queryLimit()->getFields()['fields']);
        $property = new ReflectionProperty(AbstractEntity::class, 'entityState');
        $this->assertNotSame($property->getValue($a), $property->getValue($b));
        $this->assertSame('A', $property->getValue($a)->baseline['name']);
    }

    public function testFailedWriteConsumesQueryOptionsWithoutAcceptingChanges(): void
    {
        $instance = new ReflectionProperty(FastDb::class, 'instance');
        $original = $instance->getValue();
        $db = $this->createStub(FastDb::class);
        $db->method('query')->willThrowException(new RuntimeException('SQL failure'));
        $instance->setValue(null, $db);
        try {
            $user = new User(['id' => 1, 'name' => 'A']);
            $user->name = 'B';
            $user->queryLimit()->fields(['name']);
            try { $user->update(); $this->fail('Expected SQL failure'); }
            catch (RuntimeException $error) { $this->assertSame('SQL failure', $error->getMessage()); }
            $this->assertNull($user->queryLimit()->getFields());
            $state = (new ReflectionProperty(AbstractEntity::class, 'entityState'))->getValue($user);
            $this->assertSame('A', $state->baseline['name']);
            $this->assertSame('B', $user->name);
        } finally { $instance->setValue(null, $original); }
    }

}
