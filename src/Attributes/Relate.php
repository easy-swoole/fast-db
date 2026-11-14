<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\RelationType;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public readonly string $targetEntity,
        public readonly string $targetProperty,
        public readonly RelationType $type,
        public readonly ?string $selfProperty = null,
        public readonly bool $cacheResult = false,
        public readonly ?string $configure = null,
        public readonly bool $returnAsArray = false,
    ) {
    }
}
