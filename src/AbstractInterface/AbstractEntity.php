<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Exception\Exception;
use EasySwoole\FastDb\Utility\ReflectionCache;

abstract class AbstractEntity
{

    private $compareData = [];

    abstract function tableName():string;


    function __construct(?array $data = null)
    {
        $this->init();;
    }

    private function init()
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);
        //初始化所有变量和转化
        /** @var Property $property */
        foreach ($entityRef->allProperties() as $property){
            $this->compareData[$property->name()] = null;
            if($property->defaultValue !== null){
                $this->{$property->name()} = $property->defaultValue;
                $this->compareData[$property->name()] = $property->defaultValue;
            }
            //判断转化
        }
    }
}