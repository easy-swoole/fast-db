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

class ReflectionCache
{
    use Singleton;

    private array $entityData = [];
    private array $relationData = [];

    /**
     * @throws \ReflectionException
     * @throws RuntimeError
     */
    function parseEntity(string $entityClass):EntityReflection
    {
        $key = $entityClass;
        if(isset($this->entityData[$key])){
            return $this->entityData[$key];
        }
        $ref = new \ReflectionClass($entityClass);
        if(!$ref->isSubclassOf(AbstractEntity::class)){
            throw new RuntimeError("{$entityClass} not a subclass of ".AbstractEntity::class);
        }
        $entityReflection = new EntityReflection($entityClass);

        $entityReflection->setOnDelete($this->parseClassTag(OnDelete::class,$ref));
        $entityReflection->setOnInitialize($this->parseClassTag(OnInitialize::class,$ref));
        $entityReflection->setOnInsert($this->parseClassTag(OnInsert::class,$ref));
        $entityReflection->setOnUpdate($this->parseClassTag(OnUpdate::class,$ref));


        $properties = $ref->getProperties();
        foreach ($properties as $propertyRef){
            if($propertyRef->isStatic() || (!$propertyRef->isPublic())){
                continue;
            }
            $temp = $propertyRef->getAttributes(Property::class);
            if(empty($temp)){
                continue;
            }
            $temp = $temp[0];
            $property = new Property(...$temp->getArguments());
            $property->__setName($propertyRef->name);
            if($propertyRef->getType()){
                if($propertyRef->getType()->allowsNull()){
                    $property->allowNull = true;
                }
            }
            if($propertyRef->getDefaultValue() !== null){
                $property->defaultValue = $propertyRef->getDefaultValue();
            }

            $entityReflection->addProperty($property);
        }

        $this->entityData[$key] = $entityReflection;
        return $entityReflection;
    }

    /** 返回副本，避免调用方补全 selfProperty 时修改缓存中的原始关系定义。 */
    public function parseRelation(string $entityClass, string $method): Relate
    {
        if (!isset($this->relationData[$entityClass][$method])) {
            $attributes = (new \ReflectionMethod($entityClass, $method))->getAttributes(Relate::class);
            if (empty($attributes)) {
                throw new RuntimeError("{$method} did not define Relate attribute in {$entityClass}");
            }
            $this->relationData[$entityClass][$method] = $attributes[0]->newInstance();
        }
        return clone $this->relationData[$entityClass][$method];
    }

    protected function parseClassTag(string $targetTag,\ReflectionClass $ref)
    {
        $temp = $ref->getAttributes($targetTag);
        if(!empty($temp)){
            return new $targetTag(...$temp[0]->getArguments());
        }else{
            $temp = $ref->getParentClass();
            if($temp){
                return $this->parseClassTag($targetTag,$temp);
            }else{
                return null;
            }
        }
    }
}