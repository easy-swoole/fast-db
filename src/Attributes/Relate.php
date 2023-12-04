<?php

namespace EasySwoole\FastDb\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public string $targetEntity,
        public string $targetProperty,
        public ?string $selfProperty = null,
    ){}
}