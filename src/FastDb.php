<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Pool\Exception\Exception;
use Swoole\Coroutine;
use EasySwoole\Mysqli\Config as MysqliConfig;
use Swoole\Coroutine\Scheduler;

class FastDb
{
    use Singleton;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];
    protected string $selectConnection = "default";

    protected $onQuery = null;

    function addDb(Config $config):static
    {
        $this->configs[$config->getName()] = $config;
        return $this;
    }

    function testDb(string $connectionName = "default")
    {
        if(!isset($this->configs[$connectionName])){
            throw new RuntimeError("connection {$connectionName} no register yet");
        }
        /** @var Config $config */
        $config = $this->configs[$connectionName];

        $success = false;
        $error = '';
        if(Coroutine::getCid() > 0){
            $client = new Coroutine\MySQL();
            $ret = $client->connect($config->toArray());
            if($ret){
                $success = true;
                $client->close();
            }else{
                $error = $client->connect_error;
            }
        }else{
            $scheduler = new Scheduler();
            $scheduler->add(function ()use($config,&$success,&$error){
                $client = new Coroutine\MySQL();
                $ret = $client->connect($config->toArray());
                if($ret){
                    $success = true;
                    $client->close();
                }else{
                    $error = $client->connect_error;
                }
            });;
            $scheduler->start();
        }
        if($success){
            return true;
        }else{
            throw new RuntimeError($error);
        }
    }

    function setOnQuery(callable $call):static
    {
        $this->onQuery = $call;
        return $this;
    }

    function selectConnection(string $name):static
    {
        $this->selectConnection = $name;
        return $this;
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function invoke(callable $call)
    {
        try{
            $client = $this->getClient(false);
            return call_user_func($call,$client);
        }catch (\Throwable $throwable){
            throw $throwable;
        } finally {
            if($client){
                $selectDb = $client->connectionName;
                $pool = $this->pools[$selectDb];
                try {
                    $pool->recycleObj($client);
                }catch (\Throwable $throwable){
                    trigger_error($throwable->getMessage());
                }
                $cid = Coroutine::getCid();
                unset($this->currentConnection[$cid][$selectDb]);
            }
        }
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function begin(?Connection $client = null,float $timeout = 3.0): bool
    {
        if(!$client){
            $client = $this->getClient();
        }
        if($client->isInTransaction){
            return true;
        }

        $t = microtime(true);
        $ret = $client->mysqlClient()->begin($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setConnection($client);
        $return->setRawSql("start transaction");
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            $client->isInTransaction = true;
            return true;
        }
        return false;
    }

    function commit(?Connection $client = null,float $timeout = 3.0):bool
    {
        if(!$client){
            $client = FastDb::getInstance()->currentConnection();
        }
        if(!$client){
            return true;
        }

        if(!$client->isInTransaction){
            return true;
        }

        $t = microtime(true);
        $ret = $client->mysqlClient()->commit($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setRawSql("commit");
        $return->setConnection($client);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            $client->isInTransaction = false;
            return true;
        }
        return false;
    }

    function rollback(?Connection $client = null,float $timeout = 3.0):bool
    {
        if(!$client){
            $client = FastDb::getInstance()->currentConnection();
        }
        if(!$client){
            return true;
        }

        if(!$client->isInTransaction){
            return true;
        }

        $t = microtime(true);
        $ret = $client->mysqlClient()->rollback($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setRawSql("rollback");
        $return->setConnection($client);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            $client->isInTransaction = false;
            return true;
        }
        return false;
    }

    /**
     * @throws \Throwable
     * @throws Exception
     * @throws RuntimeError
     * @throws \EasySwoole\Mysqli\Exception\Exception
     */
    function query(QueryBuilder $queryBuilder,float $timeout = null):QueryResult
    {
        $client = $this->getClient();
        $t = microtime(true);
        $ret = $client->query($queryBuilder,$timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setConnection($client);
        $return->setQueryBuilder(clone $queryBuilder);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }
        return $return;
    }

    /**
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws RuntimeError
     * @throws Exception
     */
    function rawQuery(string $sql):QueryResult
    {
        $client = $this->getClient();
        $t = microtime(true);
        $ret =  $client->rawQuery($sql);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setConnection($client);
        $return->setRawSql($sql);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }
        return $return;
    }

    function currentConnection():?Connection
    {
        $cid = Coroutine::getCid();
        if(isset($this->currentConnection[$cid][$this->selectConnection])){
            return $this->currentConnection[$cid][$this->selectConnection];
        }
        return null;
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    private function getClient(bool $autoRecycle = true):Connection
    {
        $cid = Coroutine::getCid();
        $name = $this->selectConnection;
        if(isset($this->currentConnection[$cid][$name])){
            return $this->currentConnection[$cid][$name];
        }

        if(!isset($this->configs[$name])){
            throw new RuntimeError("connection {$name} not register yet");
        }
        /** @var Config $dbConfig */
        $dbConfig = $this->configs[$name];
        if(!isset($this->pools[$name])){
            $pool = new Pool($dbConfig);
            $this->pools[$name] = $pool;
        }else{
            /** @var Pool $pool */
            $pool = $this->pools[$name];
        }
        try{
            if($autoRecycle){
                $obj = $pool->defer();
            }else{
                $obj = $pool->getObj();
            }
        }catch (\Throwable $throwable){
            throw new RuntimeError("connection {$name} error case ".$throwable->getMessage());
        }

        if($obj == null){
            throw new RuntimeError("connection {$name} error case pool empty");
        }
        /** @var Connection $obj */
        $obj->connectionName = $name;
        $this->currentConnection[$cid][$name] = $obj;

        Coroutine::defer(function ()use($cid,$name){
           unset($this->currentConnection[$cid][$name]);
        });
        return $this->currentConnection[$cid][$name];
    }

    function reset()
    {
        /** @var Pool $pool */
        foreach ($this->pools as $pool){
            $pool->reset();
        }
    }

    function preConnect()
    {
        foreach ($this->configs as $name => $config){
            /** @var Config $dbConfig */
            $dbConfig = $this->configs[$name];
            if(!isset($this->pools[$name])){
                $pool = new Pool($dbConfig);
                $this->pools[$name] = $pool;
            }else{
                /** @var Pool $pool */
                $pool = $this->pools[$name];
            }
            $pool->keepMin();
        }
    }

    function isInTransaction(?Connection $connection = null):bool
    {
        $cid = Coroutine::getCid();
        if($connection == null){
            $connection = FastDb::getInstance()->currentConnection();
        }
        if($connection){
            return $connection->isInTransaction;
        }
        return false;
    }
}