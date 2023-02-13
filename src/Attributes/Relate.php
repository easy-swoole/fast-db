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
    ){}
}