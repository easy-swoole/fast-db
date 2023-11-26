<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\FastDb\Beans\EntityReflection;
use EasySwoole\FastDb\Exception\Exception;
use EasySwoole\FastDb\Utility\ReflectionCache;

abstract class AbstractEntity
{

    abstract function tableName():string;


    function __construct()
    {
        $entityRef = ReflectionCache::getInstance()->parseEntity(static::class);

    }
}