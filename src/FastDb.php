<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\Mysqli\QueryBuilder;
use Swoole\Coroutine;

class FastDb
{
    use Singleton;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];

    function addDb(Config $config):FastDb
    {
        $this->configs[$config->getName()] = $config;

        return $this;
    }

    function selectDb(string $name):FastDb
    {
        return $this;
    }

    function invoke(callable $call)
    {

    }

    function execQuery(QueryBuilder $queryBuilder)
    {

    }

    private function getClient(string $name)
    {
        $cid = Coroutine::getCid();
        //协程环境从pool取
        if($cid > 0){

        }else{

        }
    }
}