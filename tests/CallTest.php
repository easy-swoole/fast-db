<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Tests\Model\User;
use PHPUnit\Framework\TestCase;

final class CallTest extends TestCase
{
    public function testHooksKeepProtectedInheritanceAndCallableParameterRules(): void
    {
        $entity = new InheritedHookCallOwner();
        $this->assertSame('instance', $entity->invokeHook(new Call('instanceHook')));
        $this->assertTrue($entity->invokeHook(new Call([HookCallOwner::class,'staticHook'], [Call::PARAM_CURRENT_ENTITY,'configured'])));
        $this->assertSame(['instance','configured'], $entity->events);
        $this->assertSame($entity, $entity->invokeHook(new Call(static fn($owner) => $owner)));
        $this->assertSame(InheritedHookCallOwner::class, $entity->invokeHook(new Call('get_class')));
    }

    public function testPropertyDataMatchingAnEntityPlaceholderRemainsData(): void
    {
        $entity = new User(['id' => 1]);
        $call = new Call(static fn (mixed $value): mixed => $value, [Call::PARAM_PROPERTY_VALUE]);

        $this->assertSame(
            [Call::PARAM_CURRENT_ENTITY],
            $call->buildPropertyRuntimeParams(Call::PARAM_CURRENT_ENTITY, $entity)
        );
    }

    public function testExplicitEntityAndLiteralParametersKeepTheirOrder(): void
    {
        $entity = new User(['id' => 1]);
        $call = new Call(static fn (): mixed => null, [
            Call::PARAM_PROPERTY_VALUE,
            'literal',
            Call::PARAM_CURRENT_ENTITY,
        ]);

        $this->assertSame(
            [Call::PARAM_CURRENT_ENTITY, 'literal', $entity],
            $call->buildPropertyRuntimeParams(Call::PARAM_CURRENT_ENTITY, $entity)
        );
        $this->assertSame([null, 'literal', $entity], $call->buildPropertyRuntimeParams(null, $entity));
    }
}

class HookCallOwner extends AbstractEntity
{
    public array $events = [];
    public function tableName(): string { return 'unused_hook_table'; }
    public function invokeHook(Call $call): mixed { return $this->callHook($call); }
    protected function instanceHook(): string { $this->events[] = 'instance'; return 'instance'; }
    public static function staticHook(self $owner, string $value): bool { $owner->events[] = $value; return true; }
}
class InheritedHookCallOwner extends HookCallOwner {}
