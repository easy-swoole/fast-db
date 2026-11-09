<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use PHPUnit\Framework\TestCase;

final class CoreApiCompatibilityTest extends TestCase
{
    public function testExistingPublicAndProtectedContractsRemainCompatible(): void
    {
        $contracts = json_decode(file_get_contents(__DIR__ . '/fixtures/core-public-api.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($contracts as $class => $contract) {
            $reflection = new \ReflectionClass($class);
            foreach ($contract['methods'] as $name => $expected) {
                $this->assertTrue($reflection->hasMethod($name), $class . '::' . $name);
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
                    'visibility' => $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'), 'static' => $method->isStatic(),
                    'final' => $method->isFinal(), 'abstract' => $method->isAbstract(),
                    'return' => (string) $method->getReturnType(), 'returnsReference' => $method->returnsReference(),
                    'params' => $parameters,
                ], $class . '::' . $name);
            }
            foreach ($contract['properties'] as $name => $expected) {
                $this->assertTrue($reflection->hasProperty($name), $class . '::$' . $name);
                $property = $reflection->getProperty($name);
                $this->assertSame($expected, [
                    'visibility' => $property->isPublic() ? 'public' : ($property->isProtected() ? 'protected' : 'private'), 'static' => $property->isStatic(),
                    'readonly' => $property->isReadOnly(), 'type' => (string) $property->getType(),
                    'hasDefault' => $property->hasDefaultValue(),
                    'default' => $property->hasDefaultValue() ? $this->defaultValue($property->getDefaultValue()) : null,
                ], $class . '::$' . $name);
            }
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
