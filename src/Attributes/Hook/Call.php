<?php

namespace EasySwoole\FastDb\Attributes\Hook;

class Call
{
    public readonly mixed $callback;

    public function __construct(callable|string $callback)
    {
        $this->callback = $callback;
    }
}
