<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
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

    protected array $transactionContext = [];

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

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function invoke(callable $call)
    {
        $cid = Coroutine::getCid();
        if(isset($this->transactionContext[$cid])){
            /** 在开启事务后又执行invoke可能会导致事务丢失，因为invoke结束后会立即回收链接，
             * 如果之前有声明事务，会被丢弃 ，请在invoke内声明事务
             */
            throw new RuntimeError("invoke() after begin() transaction is not allow");
        }
        $client = null;
        try{
            $client = $this->getClient();
            return call_user_func($call,$client);
        }catch (\Throwable $throwable){
            throw $throwable;
        } finally {
            //回收链接
            unset($this->currentConnection[$cid][$this->selectDb]);
        }
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function begin(float $timeout = 3.0): bool
    {
        $cid = Coroutine::getCid();
        if(!isset($this->transactionContext[$cid])){
            $this->transactionContext[$cid] = [];
            Coroutine::defer(function ()use($cid){
                unset($this->transactionContext[$cid]);
            });
        }
        if(isset($this->transactionContext[$cid][$this->selectDb])){
            return true;
        }
        $ret = $this->getClient()->mysqlClient()->begin($timeout);
        if($ret === true){
            $this->transactionContext[$cid][$this->selectDb] = true;
            return true;
        }
        return false;
    }

    function commit(float $timeout = 3.0):bool
    {
        $cid = Coroutine::getCid();
        if(!isset($this->transactionContext[$cid][$this->selectDb])){
            return true;
        }
        $ret = $this->getClient()->mysqlClient()->commit($timeout);
        if($ret === true){
            unset($this->transactionContext[$cid][$this->selectDb]);
            return true;
        }
        return false;
    }

    function rollback(float $timeout = 3.0):bool
    {
        $cid = Coroutine::getCid();
        if(!isset($this->transactionContext[$cid][$this->selectDb])){
            return true;
        }
        $ret = $this->getClient()->mysqlClient()->rollback($timeout);
        if($ret === true){
            unset($this->transactionContext[$cid][$this->selectDb]);
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
    function query(QueryBuilder $queryBuilder,float $timeout = null)
    {
        $client = $this->getClient();
        return $client->query($queryBuilder,$timeout);
    }

    /**
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws RuntimeError
     * @throws Exception
     */
    function rawQuery(string $string)
    {
        $client = $this->getClient();
        return $client->rawQuery($string);
    }

    function currentConnection():?Connection
    {
        $cid = Coroutine::getCid();
        if(isset($this->currentConnection[$cid][$this->selectDb])){
            return $this->currentConnection[$cid][$this->selectDb];
        }
        return null;
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    private function getClient():Connection
    {
        $cid = Coroutine::getCid();
        $name = $this->selectDb;
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
        $this->currentConnection[$cid][$name] = $pool->defer();
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
}