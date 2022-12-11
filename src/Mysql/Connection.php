<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Client;
use EasySwoole\Pool\ObjectInterface;

class Connection extends Client implements ObjectInterface
{

    function gc()
    {
        try {
            $this->mysqlClient()->rollback();
        }catch (\Throwable $exception){
            trigger_error($throwable->getMessage());
        }
        $this->mysqlClient()->close();
    }

    function objectRestore()
    {
        try {
            $this->mysqlClient()->rollback();
        }catch (\Throwable $exception){
            trigger_error($throwable->getMessage());
        }
        $this->reset();
    }

    function beforeUse(): ?bool
    {
        return $this->mysqlClient()->connected;
    }
}