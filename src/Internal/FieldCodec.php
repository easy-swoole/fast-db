<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Attributes\Property;

/** 集中字段转换；数据库值生成不应用隐藏字段等输出筛选规则。 */
final class FieldCodec
{
    public static function assign(Property $property, mixed $value): mixed
    {
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

    public static function values(AbstractEntity $entity, array $properties, bool $filterNull): array
    {
        $result = [];
        foreach ($properties as $property) {
            $value = $entity->{$property->name} ?? null;
            if ($filterNull && $value === null) { continue; }
            $result[$property->name] = self::compare($value);
        }
        return $result;
    }
}
