<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Entity;
use EasySwoole\FastDb\Exception\RuntimeError;

class SmartListResult extends ListResult
{
    private ?Relate $relate;
    private mixed $relateValue = null;

    function __setRelate(Relate $relate,mixed $relateValue):static
    {
        $this->relate = $relate;
        $this->relateValue = $relateValue;
        return $this;
    }

    function create():Entity
    {
        if($this->relate){
            /** @var Entity $class */
            $class = new $this->relate->targetEntity();
            $target = $this->relate->targetProperty;
            $class->{$target} = $this->relateValue;
            return $class;
        }else{
            throw new RuntimeError("smart create mode must be enable before usage");
        }
    }
}