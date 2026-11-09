<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\Spl\SplBean;

class ConvertBean extends SplBean implements ConvertObjectInterface
{
    public static function toObject(mixed $data): static
    {
        if(is_string($data)){
            $data = json_decode($data,true) ?:[];
        }
        // 构造时即提供数据，使必填嵌套 Bean 校验和 initialize() 都能读取输入。
        return new static(is_array($data) ? $data : null);
    }

    public function toValue()
    {
        return $this->__toString();
    }
}