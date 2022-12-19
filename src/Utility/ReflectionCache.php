<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnToArray;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;

class ReflectionCache
{
    use Singleton;

    private array $cache = [];

    function entityReflection(string $entityClass):EntityReflection
    {
        $key = md5($entityClass);
        if(isset($this->cache[$key])){
            return $this->cache[$key];
        }
        $ref = new \ReflectionClass($entityClass);
        if(!$ref->isSubclassOf(Entity::class)){
            throw new RuntimeError("class {$entityClass} not a sub class of ".Entity::class);
        }

        $return = new EntityReflection();


        $temp = $ref->getAttributes(OnDelete::class);
        if(!empty($temp)){
            try{
                $temp = new OnDelete(...$temp[0]->getArguments());
                $return->onDelete($temp);
            }catch (\Throwable $throwable){
                $msg = "OnDelete() attribute parse error in class {$entityClass} , strace info :{$throwable->getMessage()}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInitialize::class);
        if(!empty($temp)){
            try{
                $temp = new OnInitialize(...$temp[0]->getArguments());
                $return->onInitialize($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInitialize() attribute parse error in class {$entityClass} , strace info :{$throwable->getMessage()}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInsert::class);
        if(!empty($temp)){
            try{
                $temp = new OnInsert(...$temp[0]->getArguments());
                $return->onInsert($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInsert() attribute parse error in class {$entityClass} , strace info :{$throwable->getMessage()}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnToArray::class);
        if(!empty($temp)){
            try{
                $temp = new OnToArray(...$temp[0]->getArguments());
                $return->onToArray($temp);
            }catch (\Throwable $throwable){
                $msg = "OnToArray() attribute parse error in class {$entityClass} , strace info :{$throwable->getMessage()}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnUpdate::class);
        if(!empty($temp)){
            try{
                $temp = new OnUpdate(...$temp[0]->getArguments());
                $return->onUpdate($temp);
            }catch (\Throwable $throwable){
                $msg = "OnUpdate() attribute parse error in class {$entityClass} , strace info :{$throwable->getMessage()}";
                throw new RuntimeError($msg);
            }
        }

        $list = $ref->getProperties();
        foreach ($list as $property){
            $temp = $property->getAttributes(Property::class);
            if(!empty($temp)){
                $temp = $temp[0];
                $propertyInstance = new Property(...$temp->getArguments());
                $return->addProperty($property->name,$property->getDefaultValue());
                if($propertyInstance->isPrimaryKey){
                    if($return->getPrimaryKey() == null){
                        $return->setPrimaryKey($property->name);
                    }else{
                        throw new RuntimeError("can not redefine primary key in {$entityClass}");
                    }
                }

                $temp = $property->getAttributes(OnDelete::class);
                if(!empty($temp)){
                    try{
                        $temp = new OnDelete(...$temp[0]->getArguments());
                        $propertyInstance->onDelete($temp);
                    }catch (\Throwable $throwable){
                        $msg = "OnDelete() attribute parse error in class {$entityClass} property {$property->name} , strace info :{$throwable->getMessage()}";
                        throw new RuntimeError($msg);
                    }
                }


                $temp = $property->getAttributes(OnInitialize::class);
                if(!empty($temp)){
                    try{
                        $temp = new OnInitialize(...$temp[0]->getArguments());
                        $propertyInstance->onInitialize($temp);
                    }catch (\Throwable $throwable){
                        $msg = "OnInitialize() attribute parse error in class {$entityClass} property {$property->name} , strace info :{$throwable->getMessage()}";
                        throw new RuntimeError($msg);
                    }
                }

                $temp = $property->getAttributes(OnInsert::class);
                if(!empty($temp)){
                    try{
                        $temp = new OnInsert(...$temp[0]->getArguments());
                        $propertyInstance->onInsert($temp);
                    }catch (\Throwable $throwable){
                        $msg = "OnInsert() attribute parse error in class {$entityClass} property {$property->name} , strace info :{$throwable->getMessage()}";
                        throw new RuntimeError($msg);
                    }
                }

                $temp = $property->getAttributes(OnToArray::class);
                if(!empty($temp)){
                    try{
                        $temp = new OnToArray(...$temp[0]->getArguments());
                        $propertyInstance->onToArray($temp);
                    }catch (\Throwable $throwable){
                        $msg = "OnToArray() attribute parse error in class {$entityClass} property {$property->name} , strace info :{$throwable->getMessage()}";
                        throw new RuntimeError($msg);
                    }
                }

                $temp = $property->getAttributes(OnUpdate::class);
                if(!empty($temp)){
                    try{
                        $temp = new OnUpdate(...$temp[0]->getArguments());
                        $propertyInstance->onUpdate($temp);
                    }catch (\Throwable $throwable){
                        $msg = "OnUpdate() attribute parse error in class {$entityClass} property {$property->name} , strace info :{$throwable->getMessage()}";
                        throw new RuntimeError($msg);
                    }
                }
            }
        }

        $list = $ref->getMethods();
        foreach ($list as $method){
            $temp = $method->getAttributes(Relate::class);
            if(!empty($temp)){
                $temp = $temp[0];
                $temp = new Relate(...$temp->getArguments());
                $return->addRelate($method->getName(),$temp);
            }
        }

        if(empty($return->getProperties())){
            throw new RuntimeError("not any property defined in {$entityClass}");
        }

        if($return->getPrimaryKey() == null){
            throw new RuntimeError("primary key must be define in {$entityClass}");
        }

        $this->cache[$key] = $return;

        return $return;
    }
}