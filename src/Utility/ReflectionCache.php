<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Exception\RuntimeError;

/** 按类保存字段、关联和配置方法元数据，不保留实体实例。 */
class ReflectionCache
{
    use Singleton;

    private array $entityData = [];
    private array $relationData = [];
    private array $relationConfigurators = [];

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

        $entity = new EntityReflection($entityClass);
        $entity->setOnDelete($this->parseClassTag(OnDelete::class, $reflection));
        $entity->setOnInitialize($this->parseClassTag(OnInitialize::class, $reflection));
        $entity->setOnInsert($this->parseClassTag(OnInsert::class, $reflection));
        $entity->setOnUpdate($this->parseClassTag(OnUpdate::class, $reflection));

        foreach ($reflection->getProperties() as $propertyRef) {
            if ($propertyRef->isStatic() || !$propertyRef->isPublic()) { continue; }
            $attributes = $propertyRef->getAttributes(Property::class);
            if ($attributes === []) { continue; }

            $property = new Property(...$attributes[0]->getArguments());
            $property->__setName($propertyRef->name);
            $type = $propertyRef->getType();
            if ($type !== null && $type->allowsNull()) { $property->allowNull = true; }
            $default = $propertyRef->getDefaultValue();
            if ($default !== null) { $property->defaultValue = $default; }
            $entity->addProperty($property);
        }
        return $this->entityData[$entityClass] = $entity;
    }

    /** 返回副本，避免补全 selfProperty 时修改缓存中的原始关系定义。 */
    public function parseRelation(string $entityClass, string $method): Relate
    {
        if (!isset($this->relationData[$entityClass][$method])) {
            $reflection = new \ReflectionMethod($entityClass, $method);
            $attributes = $reflection->getAttributes(Relate::class);
            if ($attributes === []) {
                throw new RuntimeError("{$method} did not define Relate attribute in {$entityClass}");
            }
            $this->relationData[$entityClass][$method] = $attributes[0]->newInstance();
        }
        return clone $this->relationData[$entityClass][$method];
    }

    /** 配置方法的可见性按类缓存，逐父配置不再重复反射。 */
    public function relationConfigurator(string $entityClass, string $method): string
    {
        if (!isset($this->relationConfigurators[$entityClass][$method])) {
            try { $reflection = new \ReflectionMethod($entityClass, $method); }
            catch (\ReflectionException $error) {
                throw new RuntimeError('Unknown relation configurator: ' . $method, 0, $error);
            }
            if ($reflection->isPrivate() || $reflection->isStatic() || $reflection->isAbstract()) {
                throw new RuntimeError('Relation configurator must be a public or protected instance method');
            }
            $this->relationConfigurators[$entityClass][$method] = $reflection->getName();
        }
        return $this->relationConfigurators[$entityClass][$method];
    }

    protected function parseClassTag(string $targetTag, \ReflectionClass $ref)
    {
        $attributes = $ref->getAttributes($targetTag);
        if ($attributes !== []) { return new $targetTag(...$attributes[0]->getArguments()); }
        $parent = $ref->getParentClass();
        return $parent ? $this->parseClassTag($targetTag, $parent) : null;
    }
}
