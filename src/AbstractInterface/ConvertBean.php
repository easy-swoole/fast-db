<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\Spl\SplBean;

class ConvertBean extends SplBean implements ConvertObjectInterface
{
    public static function toObject(mixed $data): static
    {
        // null 保留默认对象约定；格式错误及非数组数据交给调用方处理。
        if ($data === null) {
            return new static(null);
        }
        if(is_string($data)){
            $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('ConvertBean expects an array or JSON decoding to an array');
        }
        // 构造时即提供数据，使必填嵌套 Bean 校验和 initialize() 都能读取输入。
        return new static($data);
    }

    public function toValue()
    {
        return $this->__toString();
    }
}
