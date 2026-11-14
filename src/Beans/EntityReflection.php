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
    private ?Property $autoIncrementProperty = null;

    public function __construct(
        public readonly string $entityClass,
        public readonly bool $hasCustomQueryLimit = false,
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

    public function setPrimaryKey(string $field): void
    {
        if ($this->primaryKey !== null) {
            throw new RuntimeError("can not duplicate define primary key in class {$this->entityClass}");
        }
        $this->primaryKey = $field;
    }

    public function getAutoIncrementProperty(): ?Property
    {
        return $this->autoIncrementProperty ?? ($this->primaryKey === null ? null : $this->properties[$this->primaryKey]);
    }

    public function setAutoIncrementProperty(Property $property): void
    {
        if ($this->autoIncrementProperty !== null) {
            throw new RuntimeError("Only one AutoIncrement field can be declared in {$this->entityClass}");
        }
        $this->autoIncrementProperty = $property;
    }


    function addProperty(Property $property):void
    {
        $this->properties[$property->name] = $property;
    }

    function allProperties():array
    {
        return $this->properties;
    }

}
