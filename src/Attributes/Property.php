<?php

namespace EasySwoole\FastDb\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    function __construct(
        public bool $isPrimaryKey = false
    ){

    }
}