<?php

namespace EasySwoole\FastDb;

use EasySwoole\FastDb\Attributes\Property;

abstract class Entity
{

    const Filter_Not_Null = 2;
    const Filter_Associate = 4;

    final function __construct(?array $data = null){
        $this->reflection();
        $this->initialize();
    }

    protected array $properties = [];
    protected array $propertyReflections = [];

    abstract function tableName():string;

    function toArray($filter = null):array
    {
        if($filter == null){
            return $this->properties;
        }
        $temp = $this->properties;
        if($filter == 2 || $filter == 6){
            foreach ($temp as $key => $item){
                if($item === null){
                    unset($temp[$key]);
                }
            }
        }
        if($filter == 4 || $filter == 6){
            //做关联判定处理
        }
        return $temp;
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