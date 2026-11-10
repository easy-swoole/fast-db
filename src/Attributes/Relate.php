<?php

namespace EasySwoole\FastDb\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Relate
{
    function __construct(
        public string $targetEntity,
        public string $targetProperty,
        public ?string $selfProperty = null,
        public bool $cacheResult = false,
        public ?string $type = null,
        public ?string $configure = null,
        public bool $returnAsArray = false,
    ) {
        if ($type !== null && !in_array($type, ['one', 'many'], true)) {
            throw new \InvalidArgumentException('Relation type must be one or many');
        }
    }
}