<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use PHPUnit\Framework\TestCase;

/** 锁定使用者依赖的公开签名，包括命名参数名及查询栈的公开属性。 */
final class PublicResultApiTest extends TestCase
{
    public function testPublicResultAndStackApiRemainCompatible(): void
    {
        $expected = json_decode(file_get_contents(__DIR__.'/fixtures/query-public-api.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($expected as $class => $contract) {
            $reflection = new \ReflectionClass($class);
            $methods = $properties = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $parameters = [];
                foreach ($method->getParameters() as $parameter) {
                    $parameters[$parameter->getName()] = [
                        'type' => (string) $parameter->getType(), 'optional' => $parameter->isDefaultValueAvailable(),
                        'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                        'reference' => $parameter->isPassedByReference(), 'variadic' => $parameter->isVariadic(),
                    ];
                }
                $methods[$method->getName()] = ['static' => $method->isStatic(), 'return' => (string) $method->getReturnType(), 'parameters' => $parameters];
            }
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
                $properties[$property->getName()] = ['type' => (string) $property->getType(), 'readonly' => $property->isReadOnly(),
                    'default' => $property->hasDefaultValue() ? $property->getDefaultValue() : null, 'initialized' => $property->hasDefaultValue()];
            }
            ksort($methods); ksort($properties);
            $this->assertSame($contract, ['methods' => $methods, 'properties' => $properties], $class);
        }
    }
}
