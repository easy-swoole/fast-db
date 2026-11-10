<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Exception\RuntimeError;

/** 实体内部状态，与查询条件及输出格式无关。 */
final class EntityState
{
    public array $baseline = [];

    public function changes(AbstractEntity $entity, array $properties): array
    {
        $data = $compared = [];
        foreach ($properties as $key => $property) {
            if (!array_key_exists($key, $this->baseline)) { continue; }
            $old = $this->baseline[$key];
            $value = $entity->{$key} ?? null;
            $current = FieldCodec::compare($value);
            if ($current !== $old) {
                $compared[$key] = $current;
                // 转换对象的比较值就是数据库写入值，直接复用，避免重复序列化。
                $data[$key] = $value instanceof ConvertObjectInterface ? $current : FieldCodec::encode($entity, $property, $value);
            }
        }
        return [$data, $compared];
    }

    public function primaryKey(AbstractEntity $entity, string $key): mixed
    {
        // 转换主键与比较基准统一使用数据库值；不同对象持有相同值时不视为变更。
        $current = FieldCodec::compare($entity->{$key} ?? null);
        $original = $this->baseline[$key] ?? $current;
        if ($original !== null && $current !== $original) {
            throw new RuntimeError('Cannot change entity primary key: '.$key);
        }
        return $original;
    }

    public function accept(array $changes): void
    {
        foreach ($changes as $key => $value) { $this->baseline[$key] = $value; }
    }
}
