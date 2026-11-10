<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use PHPUnit\Framework\TestCase;

final class CoreApiCompatibilityTest extends TestCase
{
    public function testExistingEntityPublicMethodSignaturesRemainCompatible(): void
    {
        $contracts = json_decode(file_get_contents(__DIR__ . '/fixtures/core-public-api.json'), true, 512, JSON_THROW_ON_ERROR);
        $reflection = new \ReflectionClass(AbstractEntity::class);
        // 只固定现有实体公开入口，允许新增方法和重构内部实现。
        foreach ($contracts[AbstractEntity::class]['methods'] as $name => $expected) {
            $this->assertTrue($reflection->hasMethod($name), AbstractEntity::class . '::' . $name);
            $method = $reflection->getMethod($name);
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $parameters[] = [
                    'name' => $parameter->getName(), 'type' => (string) $parameter->getType(),
                    'byReference' => $parameter->isPassedByReference(), 'variadic' => $parameter->isVariadic(),
                    'hasDefault' => $parameter->isDefaultValueAvailable(),
                    'default' => $parameter->isDefaultValueAvailable() ? $this->defaultValue($parameter->getDefaultValue()) : null,
                ];
            }
            $this->assertSame($expected, [
                'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
                'static' => $method->isStatic(), 'final' => $method->isFinal(), 'abstract' => $method->isAbstract(),
                'return' => (string) $method->getReturnType(), 'returnsReference' => $method->returnsReference(),
                'params' => $parameters,
            ], AbstractEntity::class . '::' . $name);
        }
    }

    private function defaultValue(mixed $value): mixed
    {
        if ($value instanceof \UnitEnum) { return ['enum' => $value::class . '::' . $value->name]; }
        if (is_array($value)) { return array_map($this->defaultValue(...), $value); }
        if (is_object($value)) { return ['class' => $value::class]; }
        return $value;
    }
}
