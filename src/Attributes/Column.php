<?php

namespace EasySwoole\FastDb\Attributes;


#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Column
{
    function __construct(
        public bool $isPrimaryKey = false,
        public $preGetter = null
    ){

    }
}