<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Exception\RuntimeError;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    public readonly string $name;
    public readonly bool $allowNull;
    private mixed $defaultValue;

    public function __construct(
        mixed $defaultValue = null,
        public readonly ?string $convertObject = null,
    ){
        $this->defaultValue = $defaultValue;
        if($this->convertObject){
            $ref = new \ReflectionClass($this->convertObject);
            if(!$ref->implementsInterface(ConvertObjectInterface::class)){
                $msg = "{$this->convertObject} did not implement ".ConvertObjectInterface::class;
                throw new RuntimeError($msg);
            }
        }
    }

    /** @internal 反射只在缓存前补齐一次字段信息。 */
    public function __setMapping(string $name, bool $allowNull, mixed $declaredDefault): void
    {
        $this->name = $name;
        $this->allowNull = $allowNull;
        $this->defaultValue = $declaredDefault ?? $this->defaultValue;
    }

    public function getDefaultValue(): mixed
    {
        return self::copyDefaultValue($this->defaultValue);
    }

    private static function copyDefaultValue(mixed $value): mixed
    {
        // 数组里的对象也需要单独复制；对象内部的复制深度由其 __clone() 决定。
        if (is_array($value)) {
            return array_map(self::copyDefaultValue(...), $value);
        }
        return is_object($value) && !($value instanceof \UnitEnum) ? clone $value : $value;
    }
}
