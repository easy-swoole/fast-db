<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Exception\RuntimeError;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    public readonly string $name;
    public bool $allowNull = false;

    public function __construct(
        public readonly bool $isPrimaryKey = false,
        public mixed $defaultValue = null,
        public readonly ?string $convertObject = null
    ){
        if($this->convertObject){
            $ref = new \ReflectionClass($this->convertObject);
            if(!$ref->implementsInterface(ConvertObjectInterface::class)){
                $msg = "{$this->convertObject} did not implement ".ConvertObjectInterface::class;
                throw new RuntimeError($msg);
            }
        }

    }

    public function __setName(string $name):void
    {
        $this->name = $name;
    }
}