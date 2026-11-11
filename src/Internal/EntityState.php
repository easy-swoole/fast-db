<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Exception\RuntimeError;

/** 实体内部状态，与查询条件及输出格式无关。 */
final class EntityState
{
    public array $baseline = [];

    /** 只比较当前值；单字段直接查基准，全部字段在首次命中时返回。 */
    public function hasChange(AbstractEntity $entity, ?string $field = null): bool
    {
        if ($field !== null) {
            return array_key_exists($field, $this->baseline)
                && FieldCodec::compare($entity->{$field} ?? null) !== $this->baseline[$field];
        }
        foreach ($this->baseline as $key => $old) {
            if (FieldCodec::compare($entity->{$key} ?? null) !== $old) { return true; }
        }
        return false;
    }

    /** 返回变化字段的当前属性值，转换对象保持原实例，不执行写入编码。 */
    public function getChanges(AbstractEntity $entity): array
    {
        $data = [];
        foreach ($this->baseline as $key => $old) {
            $value = $entity->{$key} ?? null;
            if (FieldCodec::compare($value) !== $old) { $data[$key] = $value; }
        }
        return $data;
    }

    /** 生成数据库写入值，同时收集本次写入字段的比较基准。 */
    public function changes(AbstractEntity $entity, array $properties, array &$compared): array
    {
        $data = [];
        foreach ($properties as $key => $property) {
            $old = $this->baseline[$key];
            $value = $entity->{$key} ?? null;
            $current = FieldCodec::compare($value);
            if ($current !== $old) {
                $compared[$key] = $current;
                // 转换对象的比较值就是数据库写入值，直接复用，避免重复序列化。
                $data[$key] = $value instanceof ConvertObjectInterface ? $current : FieldCodec::encode($entity, $property, $value);
            }
        }
        return $data;
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
