<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Attributes\ConvertJson;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;

class ReflectionCache
{
    use Singleton;

    private array $entityReflection = [];

    private array $methodParsedRelate = [];

    function entityReflection(string $entityClass):EntityReflection
    {
        $key = md5($entityClass);
        if(isset($this->entityReflection[$key])){
            return $this->entityReflection[$key];
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
                $msg = "OnDelete() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInitialize::class);
        if(!empty($temp)){
            try{
                $temp = new OnInitialize(...$temp[0]->getArguments());
                $return->onInitialize($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInitialize() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $temp = $ref->getAttributes(OnInsert::class);
        if(!empty($temp)){
            try{
                $temp = new OnInsert(...$temp[0]->getArguments());
                $return->onInsert($temp);
            }catch (\Throwable $throwable){
                $msg = "OnInsert() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }


        $temp = $ref->getAttributes(OnUpdate::class);
        if(!empty($temp)){
            try{
                $temp = new OnUpdate(...$temp[0]->getArguments());
                $return->onUpdate($temp);
            }catch (\Throwable $throwable){
                $msg = "OnUpdate() attribute parse error in class {$entityClass}";
                throw new RuntimeError($msg);
            }
        }

        $list = $ref->getProperties();
        foreach ($list as $property){
            $temp = $property->getAttributes(Property::class);
            if(!empty($temp)){
                $temp = $temp[0];
                $propertyInstance = new Property(...$temp->getArguments());
                /** @var \ReflectionNamedType $types */
                $types = $property->getType();
                if($types){
                    $propertyInstance->setAllowNull($types->allowsNull());
                }
                $propertyInstance->setDefaultValue($property->getDefaultValue());

                $return->addProperty($property->name,$propertyInstance);
                if($propertyInstance->isPrimaryKey){
                    if($return->getPrimaryKey() == null){
                        $return->setPrimaryKey($property->name);
                    }else{
                        throw new RuntimeError("can not redefine primary key in {$entityClass}");
                    }
                }

                $temp = $property->getAttributes(ConvertJson::class);
                if(!empty($temp)){
                    $temp = $temp[0];
                    try{
                        $jsonInstance = new ConvertJson(...$temp->getArguments());
                        $return->addPropertyConvertJson($property->name,$jsonInstance);
                    }catch (\Throwable $throwable){
                        throw new RuntimeError("{$throwable->getMessage()} in {$entityClass}");
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

        $this->entityReflection[$key] = $return;

        return $return;
    }

    function setMethodParsedRelate(string $class, string $method, Relate $relate):void
    {
        $key = md5($class.$method);
        $this->methodParsedRelate[$key] = $relate;
    }

    function getMethodParsedRelate(string $class, string $method):?Relate
    {
        $key = md5($class.$method);
        if(isset($this->methodParsedRelate[$key])){
            return$this->methodParsedRelate[$key];
        }
        return null;
    }
}