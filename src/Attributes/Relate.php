<?php

namespace EasySwoole\FastDb\Attributes;


use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Utility\ReflectionCache;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public string $targetEntity,
        public ?string $targetProperty = null,
        public ?string $selfProperty = null,
        public bool $allowCache = false,
        public bool $returnAsTargetEntity = true,
        public bool $smartCreate = false,
    ){
        //检查目标属性是否为合法entity
        $targetRef = ReflectionCache::getInstance()->entityReflection($this->targetEntity);
        if($this->targetProperty != null){
            if(!key_exists($this->targetProperty,$targetRef->getProperties())){
                throw new RuntimeError("target property {$this->targetProperty} is not define in class {$this->targetEntity}");
            }
        }else{
            $this->targetProperty = $targetRef->getPrimaryKey();
        }
    }
}