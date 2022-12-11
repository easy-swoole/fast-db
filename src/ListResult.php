<?php

namespace EasySwoole\FastDb;


class ListResult  implements \Iterator , \JsonSerializable {

    private array $data = [];
    function __construct(array $data)
    {
        $this->data = $data;
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

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
