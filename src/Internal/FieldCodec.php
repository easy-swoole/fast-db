<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Attributes\Property;

/** 集中字段转换；数据库值生成不应用隐藏字段等输出筛选规则。 */
final class FieldCodec
{
    public static function assign(AbstractEntity $entity, Property $property, mixed $value): mixed
    {
        if ($property->convertObject) {
            return self::convertObject($property, $value);
        }
        if ($property->assignCall) {
            return ($property->assignCall->callback)(...$property->assignCall->buildPropertyRuntimeParams($value, $entity));
        }
        return $value;
    }

    public static function defaultValue(Property $property): mixed
    {
        $value = $property->defaultValue;
        // 默认对象按自身 __clone 规则复制，枚举保持原值。
        if (is_object($value) && !($value instanceof \UnitEnum)) {
            $value = clone $value;
        }
        return $property->convertObject ? self::convertObject($property, $value) : $value;
    }

    private static function convertObject(Property $property, mixed $value): ?object
    {
        if ($value === null && $property->allowNull) { return null; }
        $class = $property->convertObject;
        return $value instanceof $class ? $value : $class::toObject($value);
    }

    public static function compare(mixed $value): mixed
    {
        return $value instanceof ConvertObjectInterface ? $value->toValue() : $value;
    }

    public static function encode(AbstractEntity $entity, Property $property, mixed $value): mixed
    {
        if ($value instanceof ConvertObjectInterface) { return $value->toValue(); }
        if ($property->toValue) {
            return ($property->toValue->callback)(...$property->toValue->buildPropertyRuntimeParams($value, $entity));
        }
        return $value;
    }

    public static function values(AbstractEntity $entity, array $properties, bool $filterNull, ?array &$compareValues = null): array
    {
        $result = [];
        foreach ($properties as $property) {
            $value = $entity->{$property->name} ?? null;
            if ($filterNull && $value === null && !$property->toValue) { continue; }
            $result[$property->name] = self::encode($entity, $property, $value);
            if ($compareValues !== null) {
                // 记录实际参与本字段编码的值，后续字段的转换回调可能修改实体。
                $compareValues[$property->name] = $value instanceof ConvertObjectInterface
                    ? $result[$property->name] : $value;
            }
        }
        return $result;
    }
}
