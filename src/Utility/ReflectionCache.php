<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\AutoIncrement;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Exception\RuntimeError;

/** 按类保存字段和关联元数据，不保留实体实例。 */
class ReflectionCache
{
    use Singleton;

    private array $entityData = [];
    private array $relationData = [];

    /**
     * @throws \ReflectionException
     * @throws RuntimeError
     */
    public function parseEntity(string $entityClass): EntityReflection
    {
        if (isset($this->entityData[$entityClass])) {
            return $this->entityData[$entityClass];
        }
        $reflection = new \ReflectionClass($entityClass);
        if (!$reflection->isSubclassOf(AbstractEntity::class)) {
            throw new RuntimeError("{$entityClass} not a subclass of " . AbstractEntity::class);
        }

        $entity = new EntityReflection($entityClass,
            $reflection->getMethod('queryLimit')->getDeclaringClass()->getName() !== AbstractEntity::class);
        $entity->setOnDelete($this->parseClassTag(OnDelete::class, $reflection));
        $entity->setOnInitialize($this->parseClassTag(OnInitialize::class, $reflection));
        $entity->setOnInsert($this->parseClassTag(OnInsert::class, $reflection));
        $entity->setOnUpdate($this->parseClassTag(OnUpdate::class, $reflection));

        foreach ($reflection->getProperties() as $propertyRef) {
            $attributes = $propertyRef->getAttributes(Property::class);
            $primaryKey = $propertyRef->getAttributes(PrimaryKey::class);
            $autoIncrement = $propertyRef->getAttributes(AutoIncrement::class);
            if ($propertyRef->isStatic() || !$propertyRef->isPublic() || $attributes === []) {
                if ($primaryKey !== []) {
                    throw new RuntimeError("PrimaryKey requires a public non-static Property field: {$entityClass}::{$propertyRef->name}");
                }
                if ($autoIncrement !== []) {
                    throw new RuntimeError("AutoIncrement requires a public non-static Property field: {$entityClass}::{$propertyRef->name}");
                }
                continue;
            }

            $property = $attributes[0]->newInstance();
            $property->__setName($propertyRef->name);
            $type = $propertyRef->getType();
            if ($type !== null && $type->allowsNull()) { $property->allowNull = true; }
            $default = $propertyRef->getDefaultValue();
            if ($default !== null) { $property->defaultValue = $default; }
            $entity->addProperty($property);
            if ($primaryKey !== []) {
                $primaryKey[0]->newInstance();
                $entity->setPrimaryKey($property->name);
            }
            if ($autoIncrement !== []) {
                $autoIncrement[0]->newInstance();
                $entity->setAutoIncrementProperty($property);
            }
        }
        return $this->entityData[$entityClass] = $entity;
    }

    /** 缓存解析后的关联声明，每次返回独立副本。 */
    public function parseRelation(string $entityClass, string $method): Relate
    {
        if (!isset($this->relationData[$entityClass][$method])) {
            $reflection = new \ReflectionMethod($entityClass, $method);
            $attributes = $reflection->getAttributes(Relate::class);
            if ($attributes === []) {
                throw new RuntimeError("{$method} did not define Relate attribute in {$entityClass}");
            }
            $this->relationData[$entityClass][$method] = $this->resolveRelation($entityClass, $attributes[0]->newInstance());
        }
        return clone $this->relationData[$entityClass][$method];
    }

    /** @internal 补全并校验显式传入的关联定义。 */
    public function resolveRelation(string $entityClass, Relate $relate): Relate
    {
        $target = $this->parseEntity($relate->targetEntity);
        $parent = $this->parseEntity($entityClass);
        if ($relate->selfProperty === null) {
            $relate = clone $relate;
            $relate->selfProperty = $parent->getPrimaryKey()
                ?? throw new RuntimeError('can not relate entity without primary key set');
        } elseif (!isset($parent->allProperties()[$relate->selfProperty])) {
            throw new RuntimeError("{$relate->selfProperty} is not a define property in " . $entityClass);
        }
        if (!isset($target->allProperties()[$relate->targetProperty])) {
            throw new RuntimeError("{$relate->targetProperty} is not a define property in {$relate->targetEntity}");
        }
        return $relate;
    }

    protected function parseClassTag(string $targetTag, \ReflectionClass $ref)
    {
        $attributes = $ref->getAttributes($targetTag);
        if ($attributes !== []) { return new $targetTag(...$attributes[0]->getArguments()); }
        $parent = $ref->getParentClass();
        return $parent ? $this->parseClassTag($targetTag, $parent) : null;
    }
}
