<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{
    protected function createObject(): ObjectInterface
    {
        /** @var \EasySwoole\FastDb\Config $poolConfig */
        $poolConfig = $this->getConfig();
        $con = new Connection($poolConfig->toMysqliConfig());
        $con->isForceRollback = $poolConfig->isForceRollback();
        $con->connect();
        return $con;
    }
}
