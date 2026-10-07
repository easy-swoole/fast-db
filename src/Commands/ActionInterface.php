<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Commands;

use EasySwoole\Command\Bean\Caller;
use EasySwoole\Command\Bean\Result;

interface ActionInterface
{
    public function run(Caller $caller, Result $result): void;
}
