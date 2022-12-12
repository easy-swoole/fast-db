<?php

namespace EasySwoole\FastDb\Utility;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;

class ReflectionCache
{
    use Singleton;

    private array $cache = [];

    function entityReflection(string $entityClass)
    {
        $key = md5($entityClass);
        if(isset($this->cache[$key])){
            return $this->cache[$key];
        }
        $ref = new \ReflectionClass($entityClass);
        if(!$ref->isSubclassOf(Entity::class)){
            throw new RuntimeError("relate targetEntity class {$entityClass} not a sub class of ".Entity::class);
        }
    }
}