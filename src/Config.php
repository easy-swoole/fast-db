<?php

namespace EasySwoole\FastDb;

use EasySwoole\Spl\SplBean;

class Config extends \EasySwoole\Pool\Config
{
    protected string $name;

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }
}