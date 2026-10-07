<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\Client;
use EasySwoole\Pool\ObjectInterface;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public bool $isInTransaction = false;
    public bool $isForceRollback = false;

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
            // Let the pool discard the connection if rollback fails.
            if($this->mysqlClient()->rollback() !== true){
                throw new RuntimeError('Failed to rollback connection during restore');
            }
            $this->isInTransaction = false;
        }
    }

    function beforeUse(): bool
    {
        try{
            $this->mysqlClient()->query('select 1');
            return true;
        }catch (\Throwable $throwable){
            return false;
        }
    }

    function intervalCheck(): bool
    {
        try{
            $this->mysqlClient()->query('select 1');
            return true;
        }catch (\Throwable $throwable){
            return false;
        }
    }
}