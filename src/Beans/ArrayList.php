<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;

class ArrayList  implements \Iterator , \JsonSerializable, \Countable , \ArrayAccess
{
    private array $data = [];

    function __construct(array $data)
    {
        $this->data = $data;
    }

    public function current(): mixed
    {
        return current($this->data);
    }

    public function next(): void
    {
        next($this->data);
    }

    public function key(): mixed
    {
        return key($this->data);
    }

    public function valid(): bool
    {
        return key($this->data) !== null;
    }

    public function rewind(): void
    {
        reset($this->data);
    }

    function list():array
    {
        return $this->data;
    }

    function first():array|AbstractEntity|null
    {
        if(isset($this->data[0])){
            return $this->data[0];
        }
        return null;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if(isset($this->data[$offset])){
            return $this->data[$offset];
        }
        return null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if($offset === null){
            $this->data[] = $value;
        }else{
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }


    function toArray(): array
    {
        return $this->data;
    }

    function remove(int|AbstractEntity $indexOrEntity): void
    {
        if($indexOrEntity instanceof AbstractEntity){
            foreach ($this->data as $key => $item){
                if($item === $indexOrEntity){
                    array_splice($this->data, $key, 1);
                    break;
                }
            }
        }else{
            array_splice($this->data, $indexOrEntity, 1);
        }
    }
}