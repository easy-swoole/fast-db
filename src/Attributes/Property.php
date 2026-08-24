<?php

namespace EasySwoole\FastDb\Attributes;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;
use EasySwoole\FastDb\Attributes\Hook\Call;
use EasySwoole\FastDb\Exception\RuntimeError;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Property
{
    public readonly string $name;
    public bool $allowNull = false;


    /**
     * 当创建新对象，或者是从数据库里面取数据，有对应的数据时候，会调用assignCall,
     * 当调用toArray、存、更新到数据库的时候，会调用toValue
     * 以上和convertObject不能同时存在
     */
    public function __construct(
        public readonly bool $isPrimaryKey = false,
        public mixed $defaultValue = null,
        public readonly ?string $convertObject = null,
        public Call|null $assignCall = null,
        public Call|null $toValue = null,
    ){
        if($this->convertObject && ($this->assignCall || $this->toValue)){
            throw new RuntimeError('cannot set convertObject and assignCall or toValue at the same time');
        }
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