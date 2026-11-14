<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Attributes\Hook\OnDelete;
use EasySwoole\FastDb\Attributes\Hook\OnInitialize;
use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Hook\OnUpdate;
use EasySwoole\FastDb\Attributes\Property;

/** 保存反射解析完成的实体定义，供同类实体共享。 */
final class EntityReflection
{
    public function __construct(
        public readonly string $entityClass,
        public readonly bool $hasCustomQueryLimit = false,
        private readonly array $properties = [],
        private readonly ?string $primaryKey = null,
        private readonly ?Property $autoIncrementProperty = null,
        private readonly ?OnDelete $onDelete = null,
        private readonly ?OnInitialize $onInitialize = null,
        private readonly ?OnInsert $onInsert = null,
        private readonly ?OnUpdate $onUpdate = null,
    ) {}

    public function getOnDelete(): ?OnDelete
    {
        return $this->onDelete;
    }

    public function getOnInitialize(): ?OnInitialize
    {
        return $this->onInitialize;
    }

    public function getOnInsert(): ?OnInsert
    {
        return $this->onInsert;
    }

    public function getOnUpdate(): ?OnUpdate
    {
        return $this->onUpdate;
    }

    public function getPrimaryKey(): ?string
    {
        return $this->primaryKey;
    }

    public function getAutoIncrementProperty(): ?Property
    {
        return $this->autoIncrementProperty;
    }

    public function allProperties(): array
    {
        return $this->properties;
    }
}
