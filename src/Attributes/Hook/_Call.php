<?php

namespace EasySwoole\FastDb\Attributes\Hook;

class _Call
{
    public $callback;

    function __construct(callable|string $callback)
    {
        $this->callback = $callback;
    }
}