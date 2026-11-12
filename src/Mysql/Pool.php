<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Config;
use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{
    protected function createObject(): ObjectInterface
    {
        /** @var \EasySwoole\FastDb\Config $poolConfig */
        $poolConfig = $this->getConfig();
        $config = new Config($poolConfig->toArray());
        $con = new Connection($config);
        $con->isForceRollback = $poolConfig->isForceRollback();
        $con->connect();
        return $con;
    }
}
