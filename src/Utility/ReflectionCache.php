<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Exception\RuntimeError;

class ReflectionCache
{
    use Singleton;

    private $entityData = [];

    function parseEntity(string $entityClass)
    {
        $ref = new \ReflectionClass($entityClass);
        $entityReflection = new EntityReflection($entityClass);

        $temp = $ref->getAttributes(OnDelete::class);
        if(!empty($temp)){
            try{
                $temp = new OnDelete(...$temp[0]->getArguments());
                $entityReflection->setOnDelete($temp);
            }catch (\Throwable $throwable){
                $msg = "OnDelete() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInitialize::class);
        if(!empty($temp)){
            try{
                $temp = new OnInitialize(...$temp[0]->getArguments());
                $entityReflection->setOnInitialize($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInitialize() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInsert::class);
        if(!empty($temp)){
            try{
                $temp = new OnInsert(...$temp[0]->getArguments());
                $entityReflection->setOnInsert($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInsert() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }


        $temp = $ref->getAttributes(OnUpdate::class);
        if(!empty($temp)){
            try{
                $temp = new OnUpdate(...$temp[0]->getArguments());
                $entityReflection->setOnUpdate($temp);
            }catch (\Throwable $throwable){
                $msg = "OnUpdate() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $properties = $ref->getProperties();
        foreach ($properties as $propertyRef){
            if($propertyRef->isStatic() || (!$propertyRef->isPublic())){
                continue;
            }
            $temp = $propertyRef->getAttributes(Property::class);
            if($temp){
                $temp = $temp[0];
                $property = new Property(...$temp->getArguments());
                $property->__setName($propertyRef->name);
                if($propertyRef->getType()){
                    $property->allowNull = $propertyRef->getType()->allowsNull();
                }
                $property->defaultValue = $propertyRef->getDefaultValue();
                $entityReflection->addProperty($property);
            }
        }
    }
}