<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Config;
use EasySwoole\Pool\AbstractPool;

class Pool extends AbstractPool
{
    protected function createObject()
    {
        //转错误，避免进程异常退出
        try{
            $config = new Config($this->getConfig()->toArray());
            $con =  new Connection($config);
            $con->connect();
            return $con;
        }catch (\Throwable $throwable){
            trigger_error($throwable->getMessage());
        }
    }
}