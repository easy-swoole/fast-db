<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\Config;
use EasySwoole\Mysqli\Exception\TimeoutException as DriverTimeoutException;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\ObjectInterface;
use LogicException;
use Throwable;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public TransactionRecycleAction $transactionRecycleAction = TransactionRecycleAction::Rollback;

    /** @internal FastDb 用借用次数区分同一个连接对象的多次借出。 */
    public int $poolBorrowNumber = 0;

    // 单调时钟记录最后一次成功通信的结束时间，归还连接时保留，关闭或失败时清除。
    private int|float|null $lastActiveTick = null;
    private const HEALTH_CHECK_IDLE_NANOSECONDS = 3_000_000_000;

    public function __construct(Config $config)
    {
        parent::__construct($config);
        // 成功通信只更新活跃时间；共享回调不捕获连接实例。
        static $dispatcher = null;
        $this->onQuery = $dispatcher ??= static function (bool|array $result, Client $client): void {
            $client->lastActiveTick = hrtime(true);
        };
    }

    /** 阻止继承的公开回调设置方法覆盖内部分发器；直接操作的日志由调用方负责。 */
    final public function onQuery(callable $callback): static
    {
        throw new LogicException('Connection-level query observers are not supported; record direct connection operations in caller code');
    }

    /** 直接读取协议确认的事务状态，不另存可能滞后的标记。 */
    public function isInTransaction(): bool
    {
        return $this->mysqlClient()?->inTransaction() ?? false;
    }

    public function connect(?float $timeout = null): bool
    {
        $alreadyConnected = $this->mysqlClient()?->isConnected() ?? false;
        $returned = false;
        try {
            $connected = parent::connect($timeout);
            if (!$alreadyConnected) {
                $this->lastActiveTick = hrtime(true);
            }
            $returned = true;
            return $connected;
        } finally {
            if (!$returned) { $this->lastActiveTick = null; }
        }
    }

    public function close(): bool
    {
        $closed = parent::close();
        $this->lastActiveTick = null;
        return $closed;
    }

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        return $this->executeQuery($builder, null, $timeout);
    }

    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        return $this->executeQuery(null, $query, $timeout);
    }

    private function executeQuery(?QueryBuilder $builder, ?string $sql, ?float $timeout): bool|array
    {
        $returned = false;
        try {
            $value = $builder === null ? parent::rawQuery($sql, $timeout) : parent::query($builder, $timeout);
            $returned = true;
            return $value;
        } catch (DriverTimeoutException $error) {
            // 仅在这里补充驱动超时上下文，普通 SQL 异常直接向上层传播。
            throw new TimeoutException($error->getMessage(), (int) $error->getCode(), $error,
                $builder?->getLastPrepareQuery() ?? $sql, $builder);
        } finally {
            if (!$returned) { $this->lastActiveTick = null; }
        }
    }

    public function beginTransaction(TransactionStartFlags $flags, ?float $timeout = null): bool
    {
        return $this->rawQuery($flags->toSql(), $timeout) === true;
    }

    public function commitTransaction(TransactionCompletionFlags $flags, ?float $timeout = null): bool
    {
        return $this->completeTransaction('COMMIT', $flags, $timeout);
    }

    public function rollbackTransaction(TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool
    {
        return $this->completeTransaction('ROLLBACK', $flags, $timeout);
    }

    private function completeTransaction(string $command, TransactionCompletionFlags $flags, ?float $timeout): bool
    {
        $success = $this->rawQuery($command . $flags->toSqlSuffix(), $timeout) === true;
        if ($success && $flags->releasesConnection()) { $this->close(); }
        return $success;
    }

    function gc(): void
    {
        // 淘汰时直接关闭会话，归还池中继续复用时才应用事务处理策略。
        $this->close();
    }

    function objectRestore(): void
    {
        $protocol = $this->mysqlClient();
        if ($protocol === null) { return; }
        if (!$protocol->isConnected()) {
            // 会话已断开，直接关闭，由连接池在后续借用时淘汰。
            $this->close();
            return;
        }
        if (!$protocol->inTransaction()) { return; }
        // 明确结束事务且保留连接，避免服务端 completion_type 再开事务或释放会话。
        // 执行失败的原异常交给连接池处理，None 按配置保留现有事务。
        match ($this->transactionRecycleAction) {
            TransactionRecycleAction::Commit => $this->commitTransaction(TransactionCompletionFlags::NoChainNoRelease),
            TransactionRecycleAction::Rollback => $this->rollbackTransaction(),
            TransactionRecycleAction::None => null,
        };
    }

    function beforeUse(): bool
    {
        $protocol = $this->mysqlClient();
        if ($protocol === null || !$protocol->isConnected()) {
            $this->lastActiveTick = null;
            return false;
        }
        if ($this->lastActiveTick !== null
            && hrtime(true) - $this->lastActiveTick < self::HEALTH_CHECK_IDLE_NANOSECONDS) {
            return true;
        }
        try {
            $protocol->query('select 1');
            $this->lastActiveTick = hrtime(true);
            return true;
        } catch (Throwable) {
            $this->lastActiveTick = null;
            return false;
        }
    }

    function intervalCheck(): bool
    {
        return $this->beforeUse();
    }
}
