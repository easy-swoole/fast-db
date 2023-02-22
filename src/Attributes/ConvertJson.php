<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\Json;
use EasySwoole\FastDb\Exception\RuntimeError;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ConvertJson
{
    function __construct(
        public string $className
    ){
        $ref = new \ReflectionClass($this->className);
        if(!$ref->isSubclassOf(Json::class)){
            throw new RuntimeError("{$this->className} not subclass of ".Json::class);
        }
    }
}