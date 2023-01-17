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

    protected array $transactionContext = [];

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
        $cid = Coroutine::getCid();
        if(isset($this->transactionContext[$cid])){
            /** 在开启事务后又执行invoke可能会导致事务丢失，因为invoke结束后会立即回收链接，
             * 如果之前有声明事务，会被丢弃 ，请在invoke内声明事务
             */
            throw new RuntimeError("invoke() after begin() transaction is not allow");
        }
        try{
            $client = $this->getClient();
            return call_user_func($call,$client);
        }catch (\Throwable $throwable){
            throw $throwable;
        } finally {
            foreach ($this->currentConnection[$cid] as $selectDb => $connection){
                /** @var Pool $pool */
                $pool = $this->pools[$selectDb];
                try {
                    $pool->recycleObj($connection);
                }catch (\Throwable $throwable){
                    trigger_error($throwable->getMessage());
                }
                unset($this->currentConnection[$cid][$selectDb]);
            }
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
        if(isset($this->transactionContext[$cid][$this->selectConnection])){
            return true;
        }

        $t = microtime(true);
        $client = $this->getClient();
        $ret = $client->mysqlClient()->begin($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setConnection($client);
        $return->setRawSql("start transaction");
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            $this->transactionContext[$cid][$this->selectConnection] = true;
            return true;
        }
        return false;
    }

    function commit(float $timeout = 3.0):bool
    {
        $cid = Coroutine::getCid();
        if(!isset($this->transactionContext[$cid][$this->selectConnection])){
            return true;
        }

        $t = microtime(true);
        $client = $this->getClient();
        $ret = $client->mysqlClient()->commit($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setRawSql("commit");
        $return->setConnection($client);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            unset($this->transactionContext[$cid][$this->selectConnection]);
            return true;
        }
        return false;
    }

    function rollback(float $timeout = 3.0):bool
    {
        $cid = Coroutine::getCid();
        if(!isset($this->transactionContext[$cid][$this->selectConnection])){
            return true;
        }

        $t = microtime(true);
        $client = $this->getClient();
        $ret = $client->mysqlClient()->rollback($timeout);
        $return = new QueryResult($t);
        $return->setResult($ret);
        $return->setRawSql("rollback");
        $return->setConnection($client);
        if(is_callable($this->onQuery)){
            call_user_func($this->onQuery,$return);
        }

        if($ret === true){
            unset($this->transactionContext[$cid][$this->selectConnection]);
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
    private function getClient():Connection
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