<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Config;
use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{
    protected function createObject(): ObjectInterface
    {
        /** @var Config $poolConfig */
        $poolConfig = $this->getConfig();
        $con = new Connection($poolConfig->toMysqliConfig());
        $con->transactionRecycleAction = $poolConfig->getTransactionRecycleAction();
        $con->connect();
        return $con;
    }
}
