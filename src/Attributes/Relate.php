<?php

namespace EasySwoole\FastDb\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public string $targetEntity,
        public ?string $targetProperty = null,
        public ?string $selfProperty = null,
        public bool $allowCache = true
    ){}
}