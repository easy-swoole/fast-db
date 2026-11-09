<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Tests\Model\User;
use PHPUnit\Framework\TestCase;

final class CallTest extends TestCase
{
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
