<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\RelationType;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public string $targetEntity,
        public string $targetProperty,
        public RelationType $type,
        public ?string $selfProperty = null,
        public bool $cacheResult = false,
        public ?string $configure = null,
        public bool $returnAsArray = false,
    ) {
    }
}
