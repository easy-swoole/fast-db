<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Client;
use EasySwoole\Pool\ObjectInterface;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public bool $isInTransaction = false;

    public bool $isForceRollback = false;
    public int $lastPingTime = 0;

    function gc(): void
    {
        if($this->isInTransaction || $this->isForceRollback){
            try {
                $this->mysqlClient()->rollback();
            }catch (\Throwable $throwable){
                trigger_error($throwable->getMessage());
            }
        }

        $this->close();
    }

    function objectRestore(): void
    {
        if($this->isInTransaction || $this->isForceRollback){
            try {
                $this->mysqlClient()->rollback();
            }catch (\Throwable $throwable){
                trigger_error($throwable->getMessage());
            }
        }
    }

    function beforeUse(): ?bool
    {
        try{
            $this->mysqlClient()->query('select 1');
            return true;
        }catch (\Throwable $throwable){
            return false;
        }
    }
}