<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Exception\RuntimeError;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    private readonly string $name;
    public function __construct(
        public bool $isPrimaryKey = false,
        public bool $allowNull = false,
        public string|int|float|null|bool $defaultValue = null,
        public ?string $convertObject = null
    ){
        if($this->convertObject){
            $ref = new \ReflectionClass($this->convertObject);
            if(!$ref->implementsInterface(ConvertObjectInterface::class)){
                $msg = "{$this->convertObject} did not implement ".ConvertObjectInterface::class;
                throw new RuntimeError($msg);
            }
        }

    }

    public function name():string
    {
        return $this->name;
    }

    public function __setName(string $name):void
    {
        $this->name = $name;
    }
}