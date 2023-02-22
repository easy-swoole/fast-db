<?php

namespace EasySwoole\FastDb\Attributes;


#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{

    private bool $allowNull = false;

    private mixed $defaultValue;

    function __construct(
        public bool $isPrimaryKey = false
    ){}

    /**
     * @return bool
     */
    public function isAllowNull(): bool
    {
        return $this->allowNull;
    }

    /**
     * @param bool $allowNull
     */
    public function setAllowNull(bool $allowNull): void
    {
        $this->allowNull = $allowNull;
    }

    /**
     * @return mixed
     */
    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    /**
     * @param mixed $defaultValue
     */
    public function setDefaultValue(mixed $defaultValue): void
    {
        $this->defaultValue = $defaultValue;
    }
}