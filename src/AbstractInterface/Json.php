<?php

namespace EasySwoole\FastDb\AbstractInterface;

class Json implements \JsonSerializable
{

    function restore(array $data):Json
    {
        foreach ($this as $key => $item){
            if(isset($data[$key])){
                $this->{$key} = $data[$key];
            }
        }
        return $this;
    }

    public function jsonSerialize(): mixed
    {
        $data = [];
        foreach ($this as $key => $item){
            $data[$key] = $item;
        }
        return $data;
    }

    public function __toString()
    {
        return json_encode($this->jsonSerialize(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}