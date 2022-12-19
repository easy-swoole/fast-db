<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnToArray;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    private ?OnDelete $onDelete = null;
    private ?OnInitialize $onInitialize = null;
    private ?OnInsert $onInsert = null;
    private ?OnToArray $onToArray = null;
    private ?OnUpdate $onUpdate = null;

    function __construct(
        public bool $isPrimaryKey = false
    ){

    }


    function onDelete(?OnDelete $onDelete = null): ?OnDelete
    {
        if($onDelete){
            $this->onDelete = $onDelete;
        }
        return $this->onDelete;
    }

    function  onInitialize(?OnInitialize $onInitialize = null):?OnInitialize
    {
        if($onInitialize){
            $this->onInitialize = $onInitialize;
        }
        return $this->onInitialize;
    }

    function onInsert(?OnInsert $onInsert):?OnInsert
    {
        if($onInsert){
            $this->onInsert = $onInsert;
        }
        return $this->onInsert;
    }

    function onToArray(?OnToArray $onToArray):?OnToArray
    {
        if($onToArray){
            $this->onToArray = $onToArray;
        }
        return $this->onToArray;
    }

    function onUpdate(?OnUpdate $onUpdate):?OnUpdate
    {
        if($onUpdate){
            $this->onUpdate = $onUpdate;
        }
        return $this->onUpdate;
    }
}