<?php

namespace EasySwoole\FastDb\Attributes\Hook;

class Call
{
    public $call;
    function __construct(callable $call)
    {
        $this->call = $call;
    }
}