<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Beans\QueryStack;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Mysql\TransactionContext;
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
    /**
     * 以协程 ID 和连接名索引事务上下文；实际连接及借用归属由上下文本身校验。
     * @var array<int, array<string, TransactionContext>>
     */
    protected array $transactionContexts = [];
    // 每个协程只登记一次退出清理；值表示本实例是否还负责归还自行借出的连接。
    // 提前回收不能清除此标记，否则反复借还会重复登记 defer。
    private array $contextCleanupRegistered = [];

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
        // 只有本层新借出的连接才由本层归还，避免嵌套调用提前结束外层事务。
        $ownsConnection = $this->currentConnection() === null;
        try{
            $client = $this->getClient();
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
                    unset($this->transactionContexts[$cid][$selectDb]);
                }
            }
        }
    }

    /**
     * @throws RuntimeError 启用检查且当前协程任一连接仍有活动事务时抛出。
     */
    function recycleContext(bool $checkTransaction = true):void
    {
        $cid = Coroutine::getCid();
        if(isset($this->currentConnection[$cid])){
            // 先检查全部连接再开始回收，确保拒绝回收时连接、查询栈均保持原样。
            if($checkTransaction){
                foreach ($this->currentConnection[$cid] as $selectDb => $client){
                    if($client->isInTransaction){
                        throw new RuntimeError("Cannot recycle context: connection {$selectDb} has an active transaction");
                    }
                }
            }
            foreach ($this->currentConnection[$cid] as $selectDb => $client){
                $pool = $this->pools[$selectDb];
                try {
                    $pool->recycleObj($client);
                }catch (\Throwable $throwable){
                    trigger_error($throwable->getMessage());
                }
            }
            unset($this->currentConnection[$cid]);
            unset($this->transactionContexts[$cid]);
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

        $this->getTransactionContext($client);
        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->beginTransaction($flags, $timeout);
            if ($ret === true) {
                $client->isInTransaction = true;
            }
            $return->setResult($ret);
            // 在日志回调前保存元数据；回调中的其他 SQL 可能覆盖连接的最近执行结果。
            $return->setAffectedRows($client->getLastAffectRows());
            $return->setInsertId($client->getLastInsertId());
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->finishTiming();
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

        $this->getTransactionContext($client);
        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->commitTransaction($flags, $timeout);
            if ($ret === true) {
                $client->finishTransaction(true);
                $client->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            }
            $return->setResult($ret);
            // 在日志回调前保存元数据；回调中的其他 SQL 可能覆盖连接的最近执行结果。
            $return->setAffectedRows($client->getLastAffectRows());
            $return->setInsertId($client->getLastInsertId());
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->finishTiming();
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

        $this->getTransactionContext($client);
        $return = new QueryResult(microtime(true));
        try {
            $ret = $client->rollbackTransaction($flags, $timeout);
            if ($ret === true) {
                $client->finishTransaction(false);
                $client->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            }
            $return->setResult($ret);
            // 在日志回调前保存元数据；回调中的其他 SQL 可能覆盖连接的最近执行结果。
            $return->setAffectedRows($client->getLastAffectRows());
            $return->setInsertId($client->getLastInsertId());
            return $ret === true;
        } catch (\Throwable $error) {
            $return->setException($error);
            throw $error;
        } finally {
            $return->finishTiming();
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
        $this->getTransactionContext($client);
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
            // 在日志回调前保存元数据；回调中的其他 SQL 可能覆盖连接的最近执行结果。
            $return->setAffectedRows($client->getLastAffectRows());
            $return->setInsertId($client->getLastInsertId());
        }catch (\Throwable $throwable){
            $return->setException($throwable);
            throw  $throwable;
        } finally {
            $return->finishTiming();
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
        $this->getTransactionContext($client);
        $t = microtime(true);
        $return = new QueryResult($t);
        try {
            $ret = $client->rawQuery($sql, $timeout);
            $return->setResult($ret);
            // 在日志回调前保存元数据；回调中的其他 SQL 可能覆盖连接的最近执行结果。
            $return->setAffectedRows($client->getLastAffectRows());
            $return->setInsertId($client->getLastInsertId());
        }catch (\Throwable $throwable){
            $return->setException($throwable);
            throw $throwable;
        } finally {
            $return->finishTiming();
            $return->setRawSql($sql);
            $this->notifyQuery($return);
        }
        return $return;
    }

    /**
     * 登记当前协程中指定连接的事务上下文；未传连接时使用当前选中连接。
     * 没有已借出的连接时返回 null，不会为查询上下文而主动建立数据库连接。
     */
    public function getTransactionContext(?Connection $connection = null): ?TransactionContext
    {
        $connection ??= $this->currentConnection();
        if ($connection === null) {
            return null;
        }
        // 由连接确认借用归属，不能直接复用同名连接留下的旧登记。
        $context = $connection->getTransactionContext();
        $this->registerContextCleanup($context->coroutineId);
        $this->transactionContexts[$context->coroutineId][$context->connectionName] = $context;
        return $context;
    }

    /**
     * 延迟回调仅捕获协程 ID，通过当前 FastDb 实例读取最新登记，不捕获历史连接和上下文。
     * 显式回收后仍保留登记标记，避免长生命周期协程反复借还时累积 defer。
     */
    private function registerContextCleanup(int $cid, bool $recycleConnections = false): void
    {
        if ($cid < 0) {
            return;
        }
        if (isset($this->contextCleanupRegistered[$cid])) {
            // 仅登记外部连接时不取得回收权；之后自行借出连接时才升级为负责回收。
            $this->contextCleanupRegistered[$cid] = $this->contextCleanupRegistered[$cid] || $recycleConnections;
            return;
        }
        $this->contextCleanupRegistered[$cid] = $recycleConnections;
        Coroutine::defer(function () use ($cid): void {
            try {
                // 协程已结束，必须清理未完成事务，不能使用默认的拒绝回收检查。
                if ($this->contextCleanupRegistered[$cid]) {
                    $this->recycleContext(false);
                }
            } finally {
                unset($this->currentConnection[$cid], $this->transactionContexts[$cid],
                    $this->queryStack[$cid], $this->contextCleanupRegistered[$cid]);
            }
        });
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
    private function getClient():Connection
    {
        $cid = Coroutine::getCid();
        $name = $this->selectConnection();
        if(isset($this->currentConnection[$cid][$name])){
            return $this->currentConnection[$cid][$name];
        }

        $this->registerContextCleanup($cid, true);
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
            // 统一由 FastDb 的协程级清理归还连接，避免 Pool::defer 重复登记回调。
            $obj = $pool->getObj();
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
        $this->getTransactionContext($obj);

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
            // 查询已经失败时优先保留原异常；成功查询的回调异常仍正常向外传播。
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
            $stack->duration = $result->getDuration();
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
