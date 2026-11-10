<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\Spl\SplBean;


class ConvertList implements ConvertObjectInterface
{
    protected array $list = [];

    function __construct(array $list)
    {
        $class = static::itemClass();
        if ($class !== null && !is_a($class, SplBean::class, true)) {
            throw new \InvalidArgumentException('ConvertList itemClass must extend ' . SplBean::class);
        }
        foreach ($list as $key => $item) {
            $this->list[$key] = $this->convertItem($item);
        }
    }

    /** 返回元素的 Bean 类型；null 保持原有普通列表行为。 */
    protected static function itemClass(): ?string
    {
        return null;
    }

    /** 初始化和追加共用转换，已转换的 Bean 保留原实例。 */
    private function convertItem(mixed $item): mixed
    {
        $class = static::itemClass();
        if ($class === null || $item instanceof $class) {
            return $item;
        }
        if (!is_array($item)) {
            throw new \InvalidArgumentException('ConvertList Bean items must be arrays or instances of ' . $class);
        }
        return is_a($class, ConvertObjectInterface::class, true)
            ? $class::toObject($item)
            : new $class($item);
    }

    public static function toObject(mixed $data): static
    {
        // null 表示默认空列表；其他输入必须明确符合转换格式。
        if($data === null){
            return new static([]);
        }
        if(is_string($data)){
            $data = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        }
        if(!is_array($data)){
            throw new \InvalidArgumentException('ConvertList expects an array or JSON decoding to an array');
        }
        return new static($data);
    }

    public function toValue():string
    {
        // Bean 实现 JsonSerializable，直接编码可避免每个元素变成 JSON 字符串。
        // 类型化列表始终输出 JSON 数组，删除元素留下的键不影响列表格式。
        if (static::itemClass() === null) {
            return json_encode($this->list, JSON_THROW_ON_ERROR);
        }
        return json_encode(array_values($this->list), JSON_THROW_ON_ERROR);
    }

    function toArray():array
    {
        return $this->list;
    }

    function first():mixed
    {
        $key = array_key_first($this->list);
        return $key === null ? null : $this->list[$key];
    }

    function has(mixed $item,bool $strict = false):bool
    {
        return in_array($item,$this->list,$strict);
    }

    function length():int
    {
        return count($this->list);
    }

    function remove(mixed $item,bool $strict = false):void
    {
        foreach($this->list as $key => $val){
            if($strict){
                if($val === $item){
                    unset($this->list[$key]);
                }
            }else{
                if($val == $item){
                    unset($this->list[$key]);
                }
            }
        }
    }

    function append(mixed $item):void
    {
        if($item !== null){
            $this->list[] = $this->convertItem($item);
        }
    }
}
