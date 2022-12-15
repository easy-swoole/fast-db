<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Entity;

class EntityReflection
{
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
}