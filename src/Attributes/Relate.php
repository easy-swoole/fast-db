<?php

namespace EasySwoole\FastDb\Attributes;


use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Utility\ReflectionCache;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    const RELATE_ONE_TO_NOE = 1;
    const RELATE_ONE_TO_MULTIPLE = 2;

    function __construct(
        public string $targetEntity,
        public int $relateType = self::RELATE_ONE_TO_NOE,
        public ?string $selfProperty = null,
        public ?string $targetProperty = null
    ){
        //检查目标属性是否为合法entity
        $targetRef = ReflectionCache::getInstance()->entityReflection($this->targetEntity);
        if($this->targetEntity){
            if(!isset($targetRef[$this->targetProperty])){
                throw new RuntimeError("target property {$this->targetProperty} is not define in class {$this->targetEntity}");
            }
        }
    }
}