<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnToArray;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Entity;

class EntityReflection
{
    private ?OnDelete $onDelete = null;
    private ?OnInitialize $onInitialize = null;
    private ?OnInsert $onInsert = null;
    private ?OnToArray $onToArray = null;
    private ?OnUpdate $onUpdate = null;

    private array $properties = [];
    private ?string $primaryKey = null;

    private array $methodRelates = [];

    /**
     * @return array
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param array $properties
     */
    public function setProperties(array $properties): void
    {
        $this->properties = $properties;
    }

    /**
     * @return string|null
     */
    public function getPrimaryKey(): ?string
    {
        return $this->primaryKey;
    }

    /**
     * @param string|null $primaryKey
     */
    public function setPrimaryKey(?string $primaryKey): void
    {
        $this->primaryKey = $primaryKey;
    }

    /**
     * @return array
     */
    public function getMethodRelates(): array
    {
        return $this->methodRelates;
    }

    /**
     * @param array $methodRelates
     */
    public function setMethodRelates(array $methodRelates): void
    {
        $this->methodRelates = $methodRelates;
    }

    function addProperty(string $name,mixed $value):EntityReflection
    {
        $this->properties[$name] = $value;
        return $this;
    }

    function addRelate(string $name,mixed $value):EntityReflection
    {
        $this->methodRelates[$name] = $value;
        return $this;
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

    function onInsert(?OnInsert $onInsert = null):?OnInsert
    {
        if($onInsert){
            $this->onInsert = $onInsert;
        }
        return $this->onInsert;
    }

    function onToArray(?OnToArray $onToArray = null):?OnToArray
    {
        if($onToArray){
            $this->onToArray = $onToArray;
        }
        return $this->onToArray;
    }

    function onUpdate(?OnUpdate $onUpdate = null):?OnUpdate
    {
        if($onUpdate){
            $this->onUpdate = $onUpdate;
        }
        return $this->onUpdate;
    }
}