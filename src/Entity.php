<?php

namespace EasySwoole\FastDb;

use EasySwoole\FastDb\Attributes\Property;

abstract class Entity
{


    final function __construct(){
        $this->reflection();
        $this->initialize();
    }

    protected array $properties = [];
    protected array $propertyReflections = [];

    abstract function tableName():string;

    function toArray():array
    {
        return [];
    }

    protected function initialize(): void
    {

    }

    private function reflection(): void
    {
        $ref = new \ReflectionClass(static::class);
        $list = $ref->getProperties(\ReflectionProperty::IS_PUBLIC|\ReflectionProperty::IS_PROTECTED);
        foreach ($list as $property){
            $temp = $property->getAttributes(Property::class);
            if(!empty($temp)){
                $this->properties[$property->name] = $property->getDefaultValue();
                $this->propertyReflections[$property->name] = $temp[0];
            }
        }
    }


}