<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\TimeoutException as DriverTimeoutException;
use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\ObjectInterface;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public bool $isForceRollback = false;

    /** @internal FastDb 用借用次数区分同一个连接对象的多次借出。 */
    public int $poolBorrowNumber = 0;

    // 单调时钟记录最后一次成功通信的结束时间，归还连接时保留，关闭或失败时清除。
    private int|float|null $lastActiveTick = null;
    private const HEALTH_CHECK_IDLE_NANOSECONDS = 3_000_000_000;

    public function __construct(\EasySwoole\Mysqli\Config $config)
    {
        parent::__construct($config);
        // 成功通信只更新活跃时间；共享回调不捕获连接实例。
        static $dispatcher = null;
        $this->onQuery = $dispatcher ??= static function (bool|array $result, Client $client): void {
            $client->lastActiveTick = $result === false ? null : hrtime(true);
        };
    }

    /** 阻止继承的公开回调设置方法覆盖内部分发器；直接操作的日志由调用方负责。 */
    final public function onQuery(callable $callback): static
    {
        throw new \LogicException('Connection-level query observers are not supported; record direct connection operations in caller code');
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
            if ($connected && !$alreadyConnected) {
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
        if ($closed) {
            $this->lastActiveTick = null;
        }
        return $closed;
    }

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        return $this->executeQuery($builder, $builder->getLastPrepareQuery(), $timeout);
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
            throw $this->withTimeoutContext($error, $sql, $builder);
        } finally {
            if (!$returned) { $this->lastActiveTick = null; }
        }
    }

    private function withTimeoutContext(DriverTimeoutException $error, ?string $sql, ?QueryBuilder $builder = null): TimeoutException
    {
        $exception = new TimeoutException($error->getMessage(), (int) $error->getCode(), $error);
        $exception->rawSql = $sql;
        $exception->queryBuilder = $builder === null ? null : clone $builder;
        return $exception;
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

    /** 已断开的会话无需再发 ROLLBACK，后续借用检查会淘汰连接。 */
    private function discardDisconnectedSession(): bool
    {
        $protocol = $this->mysqlClient();
        if ($protocol !== null && !$protocol->isConnected()) {
            $this->close();
            return true;
        }
        return false;
    }

    private function shouldRollback(): bool
    {
        // 已主动关闭的会话无需回滚，否则驱动会为了执行 ROLLBACK 重新建连。
        return $this->isInTransaction() || ($this->isForceRollback && $this->mysqlClient() !== null);
    }

    function gc(): void
    {
        if ($this->discardDisconnectedSession()) { return; }
        if ($this->shouldRollback()) {
            try { $this->rollbackTransaction(); }
            catch (\Throwable $error) { trigger_error($error->getMessage()); }
        }
        $this->close();
    }

    function objectRestore(): void
    {
        if ($this->discardDisconnectedSession()) { return; }
        if ($this->shouldRollback()) {
            // 不能把回滚失败的会话交给下一次借用，让连接池淘汰它。
            if ($this->rollbackTransaction() !== true) {
                throw new RuntimeError('Failed to rollback connection during restore');
            }
        }
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
            if ($protocol->query('select 1') === false) {
                $this->lastActiveTick = null;
                return false;
            }
            $this->lastActiveTick = hrtime(true);
            return true;
        } catch (\Throwable $throwable) {
            $this->lastActiveTick = null;
            return false;
        }
    }

    function intervalCheck(): bool
    {
        return $this->beforeUse();
    }
}
