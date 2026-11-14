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

        $properties = [];
        $primaryKeyName = null;
        $autoIncrementProperty = null;

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
            $property->__setMapping(
                $propertyRef->name,
                $propertyRef->getType()?->allowsNull() ?? false,
                $propertyRef->getDefaultValue()
            );
            $properties[$property->name] = $property;
            if ($primaryKey !== []) {
                $primaryKey[0]->newInstance();
                if ($primaryKeyName !== null) {
                    throw new RuntimeError("can not duplicate define primary key in class {$entityClass}");
                }
                $primaryKeyName = $property->name;
            }
            if ($autoIncrement !== []) {
                $autoIncrement[0]->newInstance();
                if ($autoIncrementProperty !== null) {
                    throw new RuntimeError("Only one AutoIncrement field can be declared in {$entityClass}");
                }
                $autoIncrementProperty = $property;
            }
        }

        return $this->entityData[$entityClass] = new EntityReflection(
            entityClass: $entityClass,
            hasCustomQueryLimit: $reflection->getMethod('queryLimit')->getDeclaringClass()->getName() !== AbstractEntity::class,
            properties: $properties,
            primaryKey: $primaryKeyName,
            autoIncrementProperty: $autoIncrementProperty ?? ($primaryKeyName === null ? null : $properties[$primaryKeyName]),
            onDelete: $this->parseClassTag(OnDelete::class, $reflection),
            onInitialize: $this->parseClassTag(OnInitialize::class, $reflection),
            onInsert: $this->parseClassTag(OnInsert::class, $reflection),
            onUpdate: $this->parseClassTag(OnUpdate::class, $reflection),
        );
    }

    /** 关联声明解析后为只读，按实体类和方法共享同一份定义。 */
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
        return $this->relationData[$entityClass][$method];
    }

    /** @internal 补全并校验显式传入的关联定义。 */
    public function resolveRelation(string $entityClass, Relate $relate): Relate
    {
        $target = $this->parseEntity($relate->targetEntity);
        $parent = $this->parseEntity($entityClass);
        $selfProperty = $relate->selfProperty ?? $parent->getPrimaryKey()
            ?? throw new RuntimeError('can not relate entity without primary key set');
        if (!isset($parent->allProperties()[$selfProperty])) {
            throw new RuntimeError("{$selfProperty} is not a define property in " . $entityClass);
        }
        if (!isset($target->allProperties()[$relate->targetProperty])) {
            throw new RuntimeError("{$relate->targetProperty} is not a define property in {$relate->targetEntity}");
        }
        if ($relate->selfProperty !== null) {
            return $relate;
        }
        // 未指定父键时用本实体主键，显式传入的声明也可以在其他实体上继续使用。
        return new Relate(
            $relate->targetEntity,
            $relate->targetProperty,
            $relate->type,
            $selfProperty,
            $relate->cacheResult,
            $relate->configure,
            $relate->returnAsArray
        );
    }

    protected function parseClassTag(string $targetTag, \ReflectionClass $ref)
    {
        $attributes = $ref->getAttributes($targetTag);
        if ($attributes !== []) { return $attributes[0]->newInstance(); }
        $parent = $ref->getParentClass();
        return $parent ? $this->parseClassTag($targetTag, $parent) : null;
    }
}
