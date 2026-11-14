<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\FastDb\Beans\QueryStack;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\Internal\QueryDiagnostics;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\Pool;
use EasySwoole\FastDb\Mysql\QueryResult;
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

    public function configureQueryStack(bool $enabled, int $maxStackSize = 256): static
    {
        ($this->diagnostics ??= new QueryDiagnostics())->configure($enabled, $maxStackSize);
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
        $connectionName = $name ?? $config->getName();
        if (isset($this->pools[$connectionName])) {
            throw new RuntimeError("Cannot replace configuration for connection {$connectionName} after its pool has been created");
        }
        // 注册时保存配置快照，外部再修改 Config 不会改变池中已有连接的配置。
        $config = clone $config;
        if ($name !== null) { $config->setName($name); }
        $this->configs[$connectionName] = $config;
        return $this;
    }

    public function getConfig(string $name = 'default'): bool|Config
    {
        return isset($this->configs[$name]) ? clone $this->configs[$name] : false;
    }

    public function testDb(string $connectionName = 'default'): bool
    {
        $config = $this->getConfig($connectionName);
        if ($config === false) { throw new RuntimeError("connection {$connectionName} no register yet"); }
        $client = new Client(new MysqliConfig($config->toArray()));
        $error = null;
        $connect = static function () use ($client, &$error): void {
            try {
                $client->connect();
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
        if ($name !== null) {
            if ($name === '' || $name === '0') { throw new \InvalidArgumentException('Connection name must be non-empty and cannot be "0"'); }
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
        $client = $this->getOrBorrowConnection();
        // 已有事务由原调用层负责结束，加入层只执行自己的业务回调。
        if ($client->isInTransaction()) { return $callback($this); }
        try {
            if (!$this->begin($client, $flags, $timeout)) { throw new RuntimeError('Failed to begin transaction'); }
            $value = $callback($this);
        } catch (\Throwable $error) {
            try { $this->rollback($client, timeout: $timeout); } catch (\Throwable) {}
            throw $error;
        }
        // 提交失败交给调用者处理，也不把提交后的日志异常当成业务回调失败。
        if (!$this->commit($client, timeout: $timeout)) { throw new RuntimeError('Failed to commit transaction'); }
        return $value;
    }

    public function begin(?Connection $client = null, TransactionStartFlags $flags = TransactionStartFlags::None,
        ?float $timeout = null): bool
    {
        $client ??= $this->getOrBorrowConnection();
        if ($client->isInTransaction()) { return true; }
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
        if ($client === null) { return true; }
        // 丢失的事务也走执行入口，由驱动报告原异常并记录本次失败。
        if (!$client->isInTransaction() && !$client->mysqlClient()?->isTransactionLost()) { return true; }
        $sql = ($rollback ? 'ROLLBACK' : 'COMMIT') . $flags->toSqlSuffix();
        return $this->execute($client, static fn () => $rollback
            ? $client->rollbackTransaction($flags, $timeout) : $client->commitTransaction($flags, $timeout),
            null, $sql)->getResult() === true;
    }

    public function isInTransaction(?Connection $connection = null): bool
    {
        return ($connection ?? $this->currentConnection())?->isInTransaction() ?? false;
    }

    /** 完成回调直接属于本次执行；总数回调在主查询回调成功结束后通知。 */
    public function query(QueryBuilder|callable $queryBuilder, ?float $timeout = null,
        ?callable $onComplete = null, ?string $connection = null, ?callable $onQuery = null,
        ?callable $onTotalQuery = null): QueryResult
    {
        if ($onQuery === null) {
            $client = $this->getOrBorrowConnection($connection);
        } else {
            // 有本次查询回调时，借用失败也要提供发起时的连接和计时。
            $startTick = hrtime(true);
            $startTime = microtime(true);
            $connectionName = $connection ?? $this->selectConnection();
            try {
                $client = $this->getOrBorrowConnection($connection);
            } catch (\Throwable $error) {
                $result = new QueryResult($startTime, microtime(true), (hrtime(true) - $startTick) / 1_000_000_000,
                    $connectionName, exception: $error, builder: $queryBuilder instanceof QueryBuilder ? $queryBuilder : null);
                return $this->finishQuery($result, $error, $onQuery);
            }
        }
        $build = $queryBuilder instanceof QueryBuilder ? null : $queryBuilder;
        $builder = $build === null ? $queryBuilder : new QueryBuilder();
        return $this->execute($client, static function () use ($client, $builder, $build, $timeout) {
            if ($build !== null) { $build($builder); }
            return $client->query($builder, $timeout);
        }, $builder, onComplete: $onComplete, totalQueryTimeout: $timeout, onQuery: $onQuery, onTotalQuery: $onTotalQuery);
    }

    public function rawQuery(string $sql, ?float $timeout = null, ?callable $onComplete = null,
        ?string $connection = null): QueryResult
    {
        $client = $this->getOrBorrowConnection($connection);
        return $this->execute($client, static fn () => $client->rawQuery($sql, $timeout), null, $sql, $onComplete);
    }

    /**
     * 执行主 SQL 和所需的总数 SQL，先记查询栈，再按顺序通知。
     * 依次执行完成回调、全局观察回调、本次主查询回调和本次总数回调。
     */
    private function execute(Connection $client, callable $operation, ?QueryBuilder $builder = null,
        ?string $sql = null, ?callable $onComplete = null, ?float $totalQueryTimeout = null,
        ?callable $onQuery = null, ?callable $onTotalQuery = null): QueryResult
    {
        $totalQueryResult = null;
        $queryResult = $this->executeResult($client, $operation, $builder, $sql, $totalQueryTimeout, $totalQueryResult);
        $firstError = $queryResult->getException() ?? $totalQueryResult?->getException();
        // 先记录已经执行的 SQL，回调里发起的查询才能按实际执行顺序入栈。
        $this->diagnostics?->record($queryResult);
        if ($totalQueryResult !== null) {
            $this->diagnostics?->record($totalQueryResult);
        }

        // 完成回调先处理内部状态，例如接受写入基准，供观察回调中的后续操作使用。
        if ($onComplete !== null) {
            try {
                $onComplete($queryResult, $client);
            } catch (\Throwable $callbackError) {
                $firstError ??= $callbackError;
            }
        }
        try {
            // 每次通知读取当前全局观察回调，主查询回调可为后续总数通知更换观察者。
            $this->diagnostics?->notify($queryResult);
            if ($totalQueryResult !== null) {
                $this->diagnostics?->notify($totalQueryResult);
            }
        } catch (\Throwable $callbackError) {
            $firstError ??= $callbackError;
        }
        return $this->finishQuery($queryResult, $firstError, $onQuery, $onTotalQuery);
    }

    /** 主查询回调也接收失败结果，之前的异常优先向外抛出。 */
    private function finishQuery(QueryResult $queryResult, ?\Throwable $firstError, ?callable $onQuery,
        ?callable $onTotalQuery = null): QueryResult
    {
        if ($onQuery !== null) {
            try {
                $onQuery($queryResult);
            } catch (\Throwable $callbackError) {
                $firstError ??= $callbackError;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }
        if ($onTotalQuery !== null) {
            $totalQueryResult = $queryResult->getTotalResult();
            if ($totalQueryResult !== null) {
                $onTotalQuery($totalQueryResult);
            }
        }
        return $queryResult;
    }

    /** 驱动返回后立即保存本次结果；异常只在此记录，统一由调用层通知后抛出。 */
    private function executeResult(Connection $client, callable $operation, ?QueryBuilder $builder, ?string $sql,
        ?float $totalQueryTimeout = null, ?QueryResult &$totalQueryResult = null): QueryResult
    {
        $startTick = hrtime(true);
        $startTime = microtime(true);
        $connectionName = $client->connectionName ?? 'default';
        $result = $exception = $affectedRows = $insertId = null;
        try {
            $result = $operation();
            $affectedRows = $client->getLastAffectRows();
            $insertId = $client->getLastInsertId();
        } catch (\Throwable $error) {
            $exception = $error;
        }
        $duration = (hrtime(true) - $startTick) / 1_000_000_000;
        $endTime = microtime(true);

        if ($exception === null && $builder !== null && in_array('SQL_CALC_FOUND_ROWS', $builder->getLastQueryOptions(), true)) {
            // 同一连接的后续 SQL 可能覆盖 FOUND_ROWS()，在通知观察者前先取总数。
            $totalSql = 'SELECT FOUND_ROWS() as count';
            $totalQueryResult = $this->executeResult(
                $client, static fn () => $client->rawQuery($totalSql, $totalQueryTimeout), null, $totalSql
            );
        }
        $totalResult = $totalQueryResult?->getException() === null ? $totalQueryResult : null;
        return new QueryResult($startTime, $endTime, $duration, $connectionName, $result, $exception,
            $affectedRows, $insertId, $sql, $builder, $totalResult);
    }

    public function invoke(callable $call): mixed
    {
        $ownsConnection = $this->currentConnection() === null;
        $client = $this->getOrBorrowConnection();
        $name = $client->connectionName;
        $borrowNumber = $client->poolBorrowNumber;
        try { return $call($client); }
        finally {
            // 回调提前归还后可能再次借到同一个对象，后一次借用归新的调用流程管理。
            if ($ownsConnection && $client->poolBorrowNumber === $borrowNumber) {
                $this->recycleConnection(Coroutine::getCid(), $name, $client);
            }
        }
    }

    public function recycleContext(): void
    {
        $cid = Coroutine::getCid();
        foreach ($this->currentConnection[$cid] ?? [] as $name => $client) {
            $this->recycleConnection($cid, $name, $client);
        }
        $this->diagnostics?->clear($cid);
    }

    private function recycleConnection(int $cid, string $name, Connection $client): void
    {
        // 回调可能已经归还连接，只有当前登记的连接才由本次流程归还。
        if (($this->currentConnection[$cid][$name] ?? null) !== $client) { return; }
        unset($this->currentConnection[$cid][$name]);
        if ($this->currentConnection[$cid] === []) { unset($this->currentConnection[$cid]); }
        try {
            $this->pools[$name]->recycleObj($client);
        } catch (\Throwable) {
            // 连接池负责处理归还失败。隔离异常，避免清理覆盖业务结果或中断其他连接归还。
        }
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
                    $this->recycleContext();
                } else {
                    $this->diagnostics?->clear($cid);
                }
            } finally {
                unset($this->currentConnection[$cid], $this->selectConnection[$cid], $this->contextCleanupRegistered[$cid]);
            }
        });
    }

    private function getOrBorrowConnection(?string $name = null): Connection
    {
        $cid = Coroutine::getCid();
        $name ??= $this->selectConnection();
        if (isset($this->currentConnection[$cid][$name])) { return $this->currentConnection[$cid][$name]; }
        $this->registerContextCleanup($cid, true);
        $pool = $this->pools[$name] ?? null;
        if ($pool === null) {
            $config = $this->getConfig($name);
            if ($config === false) { throw new RuntimeError("connection {$name} not register yet"); }
            $pool = $this->pools[$name] = new Pool($config);
        }
        // 仅在借用入口已开启查询跟踪时计时；计时值始终在本方法内消费。
        $trace = $this->diagnostics?->enabled() ?? false;
        $startTime = $trace ? microtime(true) : 0.0;
        $startTick = $trace ? hrtime(true) : 0;
        $error = null;
        try {
            $client = $pool->getObj();
            if ($client === null) { $error = new RuntimeError("connection {$name} error case pool empty"); }
        } catch (\Throwable $cause) {
            $message = $cause instanceof PoolEmpty ? 'pool empty' : $cause->getMessage();
            $error = new RuntimeError("connection {$name} error case " . $message, (int) $cause->getCode(), $cause);
        }
        // 借用错误只捕获一次，完成诊断记录后统一抛出。
        if ($trace) {
            $duration = (hrtime(true) - $startTick) / 1_000_000_000;
            $this->diagnostics?->recordAcquire($name, $startTime, microtime(true), $duration, $error);
        }
        if ($error !== null) { throw $error; }
        $client->connectionName = $name;
        $client->poolBorrowNumber++;
        $this->currentConnection[$cid][$name] = $client;
        return $client;
    }

    public function reset(): void
    {
        foreach ($this->pools as $pool) { $pool->reset(); }
    }

    public function preConnect(): void
    {
        foreach ($this->configs as $name => $config) {
            ($this->pools[$name] ??= new Pool($config))->keepMin();
        }
    }
}
