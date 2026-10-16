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
            if ($value === null && $property->allowNull) { return null; }
            return $value instanceof $property->convertObject ? $value : ($property->convertObject)::toObject($value);
        }
        if ($property->assignCall) {
            return ($property->assignCall->callback)(...$property->assignCall->buildPropertyRuntimeParams($value, $entity));
        }
        return $value;
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

    public static function values(AbstractEntity $entity, array $properties, bool $filterNull): array
    {
        $result = [];
        foreach ($properties as $property) {
            $value = $entity->{$property->name} ?? null;
            if ($filterNull && $value === null && !$property->toValue) { continue; }
            $result[$property->name] = self::encode($entity, $property, $value);
        }
        return $result;
    }
}
