<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Beans\QueryStack;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Pool\Exception\Exception;
use EasySwoole\Pool\Exception\PoolEmpty;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use EasySwoole\Mysqli\Config as MysqliConfig;

class FastDb
{
    use Singleton;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];
    protected array $selectConnection = [];
    protected mixed $onQuery = null;
    protected bool $enableQueryStack = false;

    protected array $queryStack = [];

    function isEnableQueryStack(bool $bool):static
    {
        $this->enableQueryStack = $bool;
        return $this;
    }

    function getQueryStack(?int $index = null):null|array|QueryStack
    {
        $cid = Coroutine::getCid();
        if(isset($this->queryStack[$cid])){
            if($index === null){
                return $this->queryStack[$cid];
            }
            $data = $this->queryStack[$cid];
            if($index < 0){
                $index = count($data) + $index;
            }
            if(isset($data[$index])){
                return $data[$index];
            }
        }
        return null;
    }



    function addDb(Config $config,?string $name = null):static
    {
        if($name != null){
            $config->setName($name);
        }
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

        $error = null;
        $client = new Client(new MysqliConfig($config->toArray()));
        $connect = static function () use ($client, &$error): void {
            try {
                if (!$client->connect()) {
                    throw new RuntimeError('Database connection failed');
                }
            } catch (\Throwable $failure) {
                $error = $failure;
            } finally {
                $client->close();
            }
        };
        if (Coroutine::getCid() >= 0) {
            $connect();
        } else {
            $scheduler = new Scheduler();
            $scheduler->add($connect);
            $scheduler->start();
        }
        if ($error !== null) {
            throw new RuntimeError($error->getMessage(), (int) $error->getCode(), $error);
        }
        return true;
    }

    function setOnQuery(callable $call):static
    {
        $this->onQuery = $call;
        return $this;
    }

    function selectConnection(?string $name = null):static|string
    {
        $cid = Coroutine::getCid();
        if(!empty($name)){
            if(!isset($this->selectConnection[$cid])){
                Coroutine::defer(function ()use($cid){
                    unset($this->selectConnection[$cid]);
                });
            }
            $this->selectConnection[$cid] = $name;
            return $this;
        }
        if(isset($this->selectConnection[$cid])){
            return $this->selectConnection[$cid];
        }
        return 'default';
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function invoke(callable $call)
    {
        $client = null;
        $selectDb = null;
        $ownsConnection = $this->currentConnection() === null;
        try{
            $client = $this->getClient(false);
            $selectDb = $client->connectionName;
            return call_user_func($call,$client);
        }catch (\Throwable $throwable){
            throw $throwable;
        } finally {
            if($client && $ownsConnection){
                $pool = $this->pools[$selectDb];
                try {
                    $pool->recycleObj($client);
                }catch (\Throwable $throwable){
                    trigger_error($throwable->getMessage());
                }
                $cid = Coroutine::getCid();
                if(($this->currentConnection[$cid][$selectDb] ?? null) === $client){
                    unset($this->currentConnection[$cid][$selectDb]);
                }
            }
        }
    }

    function recycleContext():void
    {
        $cid = Coroutine::getCid();
        if(isset($this->currentConnection[$cid])){
            foreach ($this->currentConnection[$cid] as $selectDb => $client){
                $pool = $this->pools[$selectDb];
                try {
                    $pool->recycleObj($client);
                }catch (\Throwable $throwable){
                    trigger_error($throwable->getMessage());
                }
            }
            unset($this->currentConnection[$cid]);
            unset($this->queryStack[$cid]);
        }
    }

    /**
     * @throws RuntimeError
     * @throws Exception
     */
    function begin(?Connection $client = null, TransactionStartFlags $flags = TransactionStartFlags::None, ?float $timeout = null): bool
    {
        if(!$client){
            $client = $this->getClient();
        }
        if($client->isInTransaction){
            return true;
        }

        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->beginTransaction($flags, $timeout);
            if ($ret === true) {
                $client->isInTransaction = true;
            }
            $return->setResult($ret);
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->setEndTime(microtime(true));
            $return->setConnection($client);
            $return->setRawSql($flags->toSql());
            $this->notifyQuery($return);
        }
    }

    function commit(?Connection $client = null, TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null):bool
    {
        if(!$client){
            $client = $this->currentConnection();
        }
        if(!$client){
            return true;
        }

        if(!$client->isInTransaction){
            return true;
        }

        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->commitTransaction($flags, $timeout);
            if ($ret === true) {
                $client->finishTransaction(true);
                $client->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            }
            $return->setResult($ret);
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->setEndTime(microtime(true));
            $return->setConnection($client);
            $return->setRawSql('COMMIT' . $flags->toSqlSuffix());
            $this->notifyQuery($return);
        }
    }

    function rollback(?Connection $client = null, TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null):bool
    {
        if(!$client){
            $client = $this->currentConnection();
        }
        if(!$client){
            return true;
        }

        if(!$client->isInTransaction){
            return true;
        }

        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->rollbackTransaction($flags, $timeout);
            if ($ret === true) {
                $client->finishTransaction(false);
                $client->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            }
            $return->setResult($ret);
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->setEndTime(microtime(true));
            $return->setConnection($client);
            $return->setRawSql('ROLLBACK' . $flags->toSqlSuffix());
            $this->notifyQuery($return);
        }
    }

    /**
     * @throws \Throwable
     * @throws Exception
     * @throws RuntimeError
     * @throws \EasySwoole\Mysqli\Exception\Exception
     */
    function query(QueryBuilder|callable $queryBuilder,float|null $timeout = null):QueryResult
    {
        $client = $this->getClient();
        $t = microtime(true);
        $return = new QueryResult($t);
        try{
            if(is_callable($queryBuilder)){
                $call = $queryBuilder;
                $queryBuilder = new QueryBuilder();
                call_user_func($call,$queryBuilder);
                $ret = $client->query($queryBuilder,$timeout);
            }else{
                $ret = $client->query($queryBuilder,$timeout);
            }
            $return->setResult($ret);
        }catch (\Throwable $throwable){
            $return->setException($throwable);
            throw  $throwable;
        } finally {
            $return->setEndTime(microtime(true));
            $return->setConnection($client);
            $return->setQueryBuilder(clone $queryBuilder);
            $this->notifyQuery($return);
        }
        return $return;
    }

    /**
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws RuntimeError
     * @throws Exception
     */
    function rawQuery(string $sql, ?float $timeout = null):QueryResult
    {
        $client = $this->getClient();
        $t = microtime(true);
        $return = new QueryResult($t);
        try {
            $ret = $client->rawQuery($sql, $timeout);
            $return->setResult($ret);
        }catch (\Throwable $throwable){
            $return->setException($throwable);
            throw $throwable;
        } finally {
            $return->setEndTime(microtime(true));
            $return->setConnection($client);
            $return->setRawSql($sql);
            $this->notifyQuery($return);
        }
        return $return;
    }

    function currentConnection():?Connection
    {
        $cid = Coroutine::getCid();
        if(isset($this->currentConnection[$cid][$this->selectConnection()])){
            return $this->currentConnection[$cid][$this->selectConnection()];
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
        $name = $this->selectConnection();
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
            $message = $throwable instanceof PoolEmpty
                ? 'pool empty'
                : $throwable->getMessage();
            throw new RuntimeError("connection {$name} error case ".$message, (int) $throwable->getCode(), $throwable);
        }

        if($obj == null){
            throw new RuntimeError("connection {$name} error case pool empty");
        }
        /** @var Connection $obj */
        $obj->connectionName = $name;
        $this->currentConnection[$cid][$name] = $obj;

        Coroutine::defer(function ()use($cid,$name){
            unset($this->currentConnection[$cid][$name]);
            unset( $this->queryStack[$cid]);
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

    function preConnect():void
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
            $connection = $this->currentConnection();
        }
        if($connection){
            return $connection->isInTransaction;
        }
        return false;
    }

    private function notifyQuery(QueryResult $result): void
    {
        $this->logStack($result);
        if (is_callable($this->onQuery)) {
            $executionException = $result->getException();
            try {
                call_user_func($this->onQuery, $result);
            } catch (\Throwable $callbackException) {
                if ($executionException === null) {
                    throw $callbackException;
                }
            }
        }
    }

    protected function logStack(QueryResult $result): void
    {
        if($this->enableQueryStack){
            $stack = new QueryStack();
            $stack->connectionName = $this->selectConnection();
            $stack->endTime = $result->getEndTime();
            $stack->startTime = $result->getStartTime();
            $stack->query = $result->getQueryBuilder();
            $stack->rawQuery = $result->getRawSql();
            $cid = Coroutine::getCid();
            if(!isset($this->queryStack[$cid])){
                $this->queryStack[$cid] = [];
            }
            $this->queryStack[$cid][] = $stack;
        }
    }

    /**
     * @param string $name
     * @return false|Config
     */
    public function getConfig(string $name = 'default'): bool|Config
    {
        return $this->configs[$name] ?? false;
    }
}
