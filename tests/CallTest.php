<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use PHPUnit\Framework\TestCase;

final class CallTest extends TestCase
{
    public function testCachedHookDefinitionIsReadOnly(): void
    {
        $cache = ReflectionCache::getInstance();
        $hook = $cache->parseEntity(CachedHookCallOwner::class)->getOnInitialize();
        $this->assertSame('instanceHook', $hook->callback);
        $this->assertSame(['instance'], (new CachedHookCallOwner())->events);

        $this->expectException(\Error::class);
        $hook->callback = 'get_class';
    }

    public function testHooksKeepProtectedInheritanceAndCallableParameterRules(): void
    {
        $entity = new InheritedHookCallOwner();
        $this->assertSame('instance', $entity->invokeHook(new Call('instanceHook')));
        $this->assertTrue($entity->invokeHook(new Call([HookCallOwner::class,'staticHook'], [Call::PARAM_CURRENT_ENTITY,'configured'])));
        $this->assertSame(['instance','configured'], $entity->events);
        $this->assertSame($entity, $entity->invokeHook(new Call(static fn($owner) => $owner)));
        $this->assertSame('method', $entity->invokeHook(new Call('get_class')));
        $this->assertSame(InheritedHookCallOwner::class, $entity->invokeHook(new Call(\Closure::fromCallable('get_class'))));
    }
}

class HookCallOwner extends AbstractEntity
{
    public array $events = [];
    public function tableName(): string { return 'unused_hook_table'; }
    public function invokeHook(Call $call): mixed { return $this->callHook($call); }
    protected function instanceHook(): string { $this->events[] = 'instance'; return 'instance'; }
    protected function get_class(): string { return 'method'; }
    public static function staticHook(self $owner, string $value): bool { $owner->events[] = $value; return true; }
}
class InheritedHookCallOwner extends HookCallOwner {}

#[OnInitialize('instanceHook')]
class CachedHookCallOwner extends HookCallOwner {}
