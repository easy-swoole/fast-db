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
        public bool $returnAsTargetEntity = true
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
        if($this->selfProperty == null){
            $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT,3);
            if(isset($trace[2]['object']) && $trace[2]['object'] instanceof Entity){
                $classClass = $trace[2]['object']::class;
                $ref = ReflectionCache::getInstance()->entityReflection($classClass);
                $this->selfProperty = $ref->getPrimaryKey();
            }
        }
    }
}