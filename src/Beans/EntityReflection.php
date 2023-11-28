<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Exception\RuntimeError;

class EntityReflection
{

    private ?OnDelete $onDelete = null;
    private ?OnInitialize $onInitialize = null;
    private ?OnInsert $onInsert = null;
    private ?OnUpdate $onUpdate = null;

    private array $properties = [];

    private ?string $primaryKey = null;

    public function __construct(
        public readonly string $entityClass
    ){}

    public function getOnDelete(): ?OnDelete
    {
        return $this->onDelete;
    }

    public function setOnDelete(?OnDelete $onDelete): void
    {
        $this->onDelete = $onDelete;
    }

    public function getOnInitialize(): ?OnInitialize
    {
        return $this->onInitialize;
    }

    public function setOnInitialize(?OnInitialize $onInitialize): void
    {
        $this->onInitialize = $onInitialize;
    }

    public function getOnInsert(): ?OnInsert
    {
        return $this->onInsert;
    }

    public function setOnInsert(?OnInsert $onInsert): void
    {
        $this->onInsert = $onInsert;
    }

    public function getOnUpdate(): ?OnUpdate
    {
        return $this->onUpdate;
    }

    public function setOnUpdate(?OnUpdate $onUpdate): void
    {
        $this->onUpdate = $onUpdate;
    }

    function getPrimaryKey():?string
    {
        return $this->primaryKey;
    }


    function addProperty(Property $property):void
    {
        $this->properties[$property->name()] = $property;
        if($property->isPrimaryKey){
            if($this->primaryKey){
                $msg = "can not duplicate define primary key in class {$this->entityClass}";
                throw new RuntimeError($msg);
            }else{
                $this->primaryKey = $property->name();
            }
        }
    }

    function allProperties():array
    {
        return $this->properties;
    }

}