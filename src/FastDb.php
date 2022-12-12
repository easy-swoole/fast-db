<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Pool\Exception\Exception;
use Swoole\Coroutine;
use EasySwoole\Mysqli\Config as MysqliConfig;

class FastDb
{
    use Singleton;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];

    protected string $selectDb = "default";

    function addDb(Config $config):FastDb
    {
        $this->configs[$config->getName()] = $config;

        return $this;
    }

    function selectDb(string $name):FastDb
    {
        $this->selectDb = $name;
        return $this;
    }

    function invoke(callable $call)
    {

    }

    function query(QueryBuilder $queryBuilder)
    {

    }

    function rawQuery(string $string)
    {
        $client = $this->getClient($this->selectDb);
        var_dump($client->rawQuery($string));
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    private function getClient(string $name):Client
    {
        if(!isset($this->configs[$name])){
            throw new RuntimeError("connection {$name} not register yet");
        }
        /** @var Config $dbConfig */
        $dbConfig = $this->configs[$name];
        $cid = Coroutine::getCid();
        if(!isset($this->pools[$name])){
            $pool = new Pool($dbConfig);
        }else{
            /** @var Pool $pool */
            $pool = $this->pools[$name];
        }
        return $pool->defer();
    }

    function reset()
    {
        /** @var Pool $pool */
        foreach ($this->pools as $pool){
            $pool->reset();
        }
    }
}