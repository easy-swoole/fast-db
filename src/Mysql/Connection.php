<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Client;
use EasySwoole\Pool\ObjectInterface;
use Swoole\Coroutine\MySQL;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public bool $isInTransaction = false;

    public int $lastPingTime = 0;

    function gc()
    {
        if($this->isInTransaction){
            try {
                $this->mysqlClient()->rollback();
            }catch (\Throwable $throwable){
                trigger_error($throwable->getMessage());
            }
        }

        $this->close();
    }

    function objectRestore()
    {
        if($this->isInTransaction){
            try {
                $this->mysqlClient()->rollback();
            }catch (\Throwable $throwable){
                trigger_error($throwable->getMessage());
            }
        }
    }

    function beforeUse(): ?bool
    {
        if($this->mysqlClient() instanceof MySQL){
            return $this->mysqlClient()->connected;
        }else{
            return $this->mysqlClient()->ping();
        }
    }
}