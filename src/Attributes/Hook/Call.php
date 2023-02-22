<?php

namespace EasySwoole\FastDb\Attributes\Hook;

class Call
{
    public $callback;

    function __construct(callable|string $callback)
    {
        $this->callback = $callback;
    }
}