<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Attributes\Hook\OnDelete;
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
            $temp = new OnDelete(...$temp[0]->getArguments());
        }

        $list = $ref->getProperties();
        foreach ($list as $property){
            $temp = $property->getAttributes(Property::class);
            if(!empty($temp)){
                $temp = $temp[0];
                $temp = new Property(...$temp->getArguments());
                $return->addProperty($property->name,$property->getDefaultValue());
                if($temp->isPrimaryKey){
                    if($return->getPrimaryKey() == null){
                        $return->setPrimaryKey($property->name);
                    }else{
                        throw new RuntimeError("can not redefine primary key in {$entityClass}");
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