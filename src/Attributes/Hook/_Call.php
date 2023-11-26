<?php

namespace EasySwoole\FastDb\Attributes\Hook;

class _Call
{
    public $callback;

    function __construct(callable $callback)
    {
        $this->callback = $callback;
    }
}