<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Beans\QueryStack;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Internal\QueryDiagnostics;
use EasySwoole\FastDb\Internal\ExecutionFrame;
use EasySwoole\FastDb\Internal\TransactionRunner;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Mysql\TransactionContext;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config as MysqliConfig;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\Exception\PoolEmpty;
use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;

/** 管理配置、协程连接借用与执行入口；执行状态和诊断各有唯一拥有者。 */
class FastDb
{
    use Singleton;

    // 未配置诊断时保持为空；读取和清理都不应额外创建诊断对象。
    private ?QueryDiagnostics $diagnostics = null;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];
    protected array $selectConnection = [];

    // 每个协程只登记一次退出清理；提前回收后仍保留登记，防止退出回调累积。
    // false 表示只登记了外部连接/选库，本实例尚未承担池连接的归还责任。
    private array $contextCleanupRegistered = [];

    public function isEnableQueryStack(bool $bool, int $maxStackSize = 256): static
    {
        ($this->diagnostics ??= new QueryDiagnostics())->configure($bool, $maxStackSize);
        return $this;
    }

    public function getQueryStack(?int $index = null): null|array|QueryStack
    {
        return $this->diagnostics?->stack(Coroutine::getCid(), $index);
    }

    public function setOnQuery(callable $call): static
    {
        ($this->diagnostics ??= new QueryDiagnostics())->setOnQuery($call);
        return $this;
    }

    public function addDb(Config $config, ?string $name = null): static
    {
        if ($name !== null) { $config->setName($name); }
        $this->configs[$config->getName()] = $config;
        return $this;
    }

    public function getConfig(string $name = 'default'): bool|Config
    {
        return $this->configs[$name] ?? false;
    }

    public function testDb(string $connectionName = 'default'): bool
    {
        $config = $this->getConfig($connectionName);
        if ($config === false) { throw new RuntimeError("connection {$connectionName} no register yet"); }
        $client = new Client(new MysqliConfig($config->toArray()));
        $error = null;
        $connect = static function () use ($client, &$error): void {
            try {
                if (!$client->connect()) { throw new RuntimeError('Database connection failed'); }
            } catch (\Throwable $failure) {
                $error = $failure;
            } finally {
                $client->close();
            }
        };
        if (Coroutine::getCid() >= 0) { $connect(); }
        else {
            $scheduler = new Scheduler();
            $scheduler->add($connect);
            $scheduler->start();
        }
        if ($error !== null) { throw new RuntimeError($error->getMessage(), (int) $error->getCode(), $error); }
        return true;
    }

    public function selectConnection(?string $name = null): static|string
    {
        $cid = Coroutine::getCid();
        if (!empty($name)) {
            $this->registerContextCleanup($cid);
            $this->selectConnection[$cid] = $name;
            return $this;
        }
        return $this->selectConnection[$cid] ?? 'default';
    }

    public function withConnection(string $name, callable $callback): mixed
    {
        if ($name === '' || $name === '0') { throw new \InvalidArgumentException('Connection name must be non-empty and cannot be "0"'); }
        $cid = Coroutine::getCid();
        if (!isset($this->configs[$name]) && !isset($this->currentConnection[$cid][$name])) {
            throw new RuntimeError('connection ' . $name . ' not register yet');
        }
        $previous = $this->selectConnection();
        $this->selectConnection($name);
        try { return $callback($this); }
        finally { $this->selectConnection($previous); }
    }

    public function currentConnection(): ?Connection
    {
        return $this->currentConnection[Coroutine::getCid()][$this->selectConnection()] ?? null;
    }

    public function transaction(callable $callback, TransactionStartFlags $flags = TransactionStartFlags::None,
        ?float $timeout = null): mixed
    {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new \InvalidArgumentException('Transaction timeout must be finite and greater than zero');
        }
        return TransactionRunner::run($this, $this->getClient(), $callback, $flags, $timeout);
    }

    public function begin(?Connection $client = null, TransactionStartFlags $flags = TransactionStartFlags::None,
        ?float $timeout = null): bool
    {
        $client ??= $this->getClient();
        $client->__getTransactionScope()?->assertControl($client, $flags->toSql());
        if ($client->isInTransaction) { return true; }
        return $this->execute($client, static fn () => $client->beginTransaction($flags, $timeout),
            null, $flags->toSql())->getResult() === true;
    }

    public function commit(?Connection $client = null,
        TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool
    {
        return $this->completeTransaction(false, $client, $flags, $timeout);
    }

    public function rollback(?Connection $client = null,
        TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool
    {
        return $this->completeTransaction(true, $client, $flags, $timeout);
    }

    private function completeTransaction(bool $rollback, ?Connection $client,
        TransactionCompletionFlags $flags, ?float $timeout): bool
    {
        $client ??= $this->currentConnection();
        $sql = ($rollback ? 'ROLLBACK' : 'COMMIT') . $flags->toSqlSuffix();
        $client?->__getTransactionScope()?->assertControl($client, $sql);
        if ($client === null || !$client->isInTransaction) { return true; }
        return $this->execute($client, static fn () => $rollback
            ? $client->rollbackTransaction($flags, $timeout) : $client->commitTransaction($flags, $timeout),
            null, $sql)->getResult() === true;
    }

    public function isInTransaction(?Connection $connection = null): bool
    {
        return ($connection ?? $this->currentConnection())?->isInTransaction ?? false;
    }

    /** 完成回调直接属于本次执行；指定连接不会改变回调可见的选库。 */
    public function query(QueryBuilder|callable $queryBuilder, ?float $timeout = null,
        ?callable $onComplete = null, ?string $connection = null): QueryResult
    {
        $client = $this->getClient($connection);
        $build = is_callable($queryBuilder) ? $queryBuilder : null;
        $builder = $build === null ? $queryBuilder : new QueryBuilder();
        return $this->execute($client, static function (ExecutionFrame $execution) use ($client, $builder, $build, $timeout) {
            if ($build !== null) { $build($builder); }
            $scope = $client->__getTransactionScope();
            if ($scope === null) {
                $execution->start();
                return $client->query($builder, $timeout);
            }
            $frame = $scope->enterExecution($client, $builder->getLastPrepareQuery());
            try {
                $execution->start();
                return $client->query($builder, $timeout);
            }
            finally { $scope->leaveExecution($frame); }
        }, $builder, null, $onComplete);
    }

    public function rawQuery(string $sql, ?float $timeout = null, ?callable $onComplete = null,
        ?string $connection = null): QueryResult
    {
        $client = $this->getClient($connection);
        return $this->execute($client, static fn () => $client->rawQuery($sql, $timeout), null, $sql, $onComplete);
    }

    private function execute(Connection $client, callable $operation, ?QueryBuilder $builder = null,
        ?string $sql = null, ?callable $onComplete = null): QueryResult
    {
        $this->getTransactionContext($client);
        if ($sql !== null && $client->__getTransactionScope() !== null) {
            $original = $operation;
            $operation = static function (ExecutionFrame $execution) use ($client, $sql, $original) {
                $scope = $client->__getTransactionScope();
                if ($scope === null) { return $original($execution); }
                $frame = $scope->enterExecution($client, $sql);
                try { return $original($execution); }
                finally { $scope->leaveExecution($frame); }
            };
        }
        $frame = new ExecutionFrame($builder, $sql, $onComplete, true);
        $frame->result->setConnectionName($client->connectionName ?? 'default');
        if ($builder === null) { $frame->start(); }
        try {
            return $client->runFrame($frame, $operation);
        } finally {
            try {
                // 工厂或完成回调中首次启用诊断，也适用于本次已完成的 SQL。
                $this->diagnostics?->notify($frame->result);
                if ($frame->count !== null) { $this->diagnostics?->notify($frame->count->result); }
            } catch (\Throwable $error) {
                // 查询或完成回调已失败时保留原异常，只有原流程成功才抛出日志回调异常。
                if ($frame->failure === null) { throw $error; }
            }
        }
    }

    public function getTotalCount(QueryResult $result): ?int
    {
        return $result->getTotalCount();
    }

    public function getTotalResult(QueryResult $result): ?QueryResult
    {
        return $result->getTotalResult();
    }

    public function getTransactionContext(?Connection $connection = null): ?TransactionContext
    {
        $connection ??= $this->currentConnection();
        if ($connection === null) { return null; }
        $this->registerContextCleanup(Coroutine::getCid());
        return $connection->getTransactionContext();
    }

    public function invoke(callable $call): mixed
    {
        $ownsConnection = $this->currentConnection() === null;
        $client = $this->getClient();
        $name = $client->connectionName;
        try { return $call($client); }
        finally {
            if ($ownsConnection) {
                $this->returnConnection($name, $client);
                $cid = Coroutine::getCid();
                if (($this->currentConnection[$cid][$name] ?? null) === $client) {
                    unset($this->currentConnection[$cid][$name]);
                }
            }
        }
    }

    public function recycleContext(bool $checkTransaction = true): void
    {
        $cid = Coroutine::getCid();
        $connections = $this->currentConnection[$cid] ?? [];
        // 在产生回收副作用之前检查全部连接，失败时查询栈和借用均保持原状。
        foreach ($connections as $name => $client) {
            if (($client->__getTransactionScope()?->depth ?? 0) > 0) {
                throw new RuntimeError('Cannot recycle a connection owned by an active transaction() scope');
            }
            if ($checkTransaction && $client->isInTransaction) {
                throw new RuntimeError("Cannot recycle context: connection {$name} has an active transaction");
            }
        }
        foreach ($connections as $name => $client) { $this->returnConnection($name, $client); }
        unset($this->currentConnection[$cid]);
        $this->diagnostics?->clear($cid);
    }

    private function returnConnection(string $name, Connection $client): void
    {
        try { $this->pools[$name]->recycleObj($client); }
        catch (\Throwable $error) { trigger_error($error->getMessage()); }
    }

    private function registerContextCleanup(int $cid, bool $recycleConnections = false): void
    {
        if ($cid < 0) { return; }
        if (isset($this->contextCleanupRegistered[$cid])) {
            $this->contextCleanupRegistered[$cid] = $this->contextCleanupRegistered[$cid] || $recycleConnections;
            return;
        }
        $this->contextCleanupRegistered[$cid] = $recycleConnections;
        Coroutine::defer(function () use ($cid): void {
            try {
                if ($this->contextCleanupRegistered[$cid]) {
                    foreach ($this->currentConnection[$cid] ?? [] as $client) {
                        $scope = $client->__getTransactionScope();
                        if (($scope?->depth ?? 0) > 0) {
                            try { $client->close(); }
                            finally { $scope->depth = 0; $client->__setTransactionScope(null); }
                        }
                    }
                    $this->recycleContext(false);
                }
            } finally {
                unset($this->currentConnection[$cid], $this->selectConnection[$cid], $this->contextCleanupRegistered[$cid]);
                $this->diagnostics?->clear($cid);
            }
        });
    }

    private function getClient(?string $name = null): Connection
    {
        $cid = Coroutine::getCid();
        $name ??= $this->selectConnection();
        if (isset($this->currentConnection[$cid][$name])) { return $this->currentConnection[$cid][$name]; }
        $this->registerContextCleanup($cid, true);
        $config = $this->getConfig($name);
        if ($config === false) { throw new RuntimeError("connection {$name} not register yet"); }
        $pool = $this->pools[$name] ??= new Pool($config);
        // 仅在借用入口已开启查询跟踪时计时；计时值始终在本方法内消费。
        $trace = $this->diagnostics?->enabled() ?? false;
        $startTime = $trace ? microtime(true) : 0.0;
        $startTick = $trace ? hrtime(true) : 0;
        $error = null;
        try {
            try { $client = $pool->getObj(); }
            catch (\Throwable $cause) {
                $message = $cause instanceof PoolEmpty ? 'pool empty' : $cause->getMessage();
                throw new RuntimeError("connection {$name} error case " . $message, (int) $cause->getCode(), $cause);
            }
            if ($client === null) { throw new RuntimeError("connection {$name} error case pool empty"); }
        } catch (\Throwable $failure) {
            $error = $failure;
            throw $failure;
        } finally {
            if ($trace) {
                $duration = (hrtime(true) - $startTick) / 1_000_000_000;
                $this->diagnostics?->recordAcquire($name, $startTime, microtime(true), $duration, $error);
            }
        }
        $client->connectionName = $name;
        $this->currentConnection[$cid][$name] = $client;
        $this->getTransactionContext($client);
        return $client;
    }

    public function reset(): void
    {
        foreach ($this->currentConnection as $connections) {
            foreach ($connections as $client) {
                if (($client->__getTransactionScope()?->depth ?? 0) > 0) {
                    throw new RuntimeError('Cannot reset pools while transaction() scopes are active');
                }
            }
        }
        foreach ($this->pools as $pool) { $pool->reset(); }
    }

    public function preConnect(): void
    {
        foreach ($this->configs as $name => $config) {
            ($this->pools[$name] ??= new Pool($config))->keepMin();
        }
    }
}
