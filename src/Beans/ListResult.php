<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;

class ListResult  implements \Iterator , \JsonSerializable, \Countable
{

    private array $data = [];
    private ?int $totalCount = null;

    function __construct(array $data,?int $totalCount = null)
    {
        $this->data = $data;
        $this->totalCount = $totalCount;
    }

    private int $iteratorKey = 0;

    public function current(): mixed
    {
        return $this->data[$this->iteratorKey];
    }

    public function next(): void
    {
        $this->iteratorKey++;
    }

    public function key(): mixed
    {
        return $this->iteratorKey;
    }

    public function valid(): bool
    {
        return isset($this->data[$this->iteratorKey]);
    }

    public function rewind(): void
    {
        $this->iteratorKey = 0;
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

    function totalCount():?int
    {
        return $this->totalCount;
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }

    public function count(): int
    {
        return count($this->data);
    }


    function toArray()
    {
        return $this->data;
    }

}