<?php

namespace EasySwoole\FastDb\AbstractInterface;


class ConvertList implements ConvertObjectInterface
{
    protected array $list = [];

    function __construct(array $list)
    {
        $this->list = $list;
    }

    public static function toObject(mixed $data): static
    {
        if(empty($data)){
            $data = [];
        }
        if(!is_array($data)){
            $data = json_decode($data,true) ?? [];
        }
        return new static($data);
    }

    public function toValue():string
    {
        return json_encode($this->list);
    }

    function toArray():array
    {
        return $this->list;
    }

    function first():mixed
    {
        if(isset($this->list[0])){
            return $this->list[0];
        }
        return null;
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
            $this->list[] = $item;
        }
    }
}