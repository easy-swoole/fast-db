<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\Spl\SplBean;

class ConvertJson extends SplBean implements ConvertObjectInterface
{
    public static function toObject(mixed $data): object
    {
        if(is_string($data)){
            $data = json_decode($data,true) ?:[];
        }
        $object = new static();
        if(is_array($data)){
            $object->restore($data);
        }
        return $object;
    }

    public function toValue()
    {
        return $this->__toString();
    }
}