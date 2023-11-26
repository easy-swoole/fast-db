<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    private readonly string $name;
    public function __construct(
        public bool $isPrimaryKey = false,
        public bool $allowNull = false,
        public string|int|float|null|bool $defaultValue = null,
        public ?ConvertObjectInterface $convertObject = null
    ){}

    public function name():string
    {
        return $this->name;
    }

    public function __setName(string $name):void
    {
        $this->name = $name;
    }
}