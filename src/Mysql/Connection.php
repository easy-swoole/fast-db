<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use Swoole\Coroutine;
use EasySwoole\FastDb\Internal\TransactionSql;
use EasySwoole\FastDb\Internal\QueryExecutor;
use EasySwoole\FastDb\Internal\TransactionScope;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception as QueryException;
use EasySwoole\Mysqli\Exception\TimeoutException as DriverTimeoutException;
use EasySwoole\FastDb\Exception\TimeoutException;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;
use EasySwoole\Pool\ObjectInterface;

class Connection extends Client implements ObjectInterface
{
    public string $connectionName;
    public bool $isInTransaction = false;
    public bool $isForceRollback = false;

    private ?TransactionContext $transactionContext = null;
    // 只在回调事务参与期间或标记 rollback-only 时持有，结束后立即释放。
    private ?TransactionScope $transactionScope = null;
    private ?\stdClass $pendingCompletion = null;
    private ?\stdClass $deferredCountObserver = null;
    // 单调时钟记录最后一次成功通信的结束时间，归还连接时保留，关闭或失败时清除。
    private int|float|null $lastActiveTick = null;
    private const HEALTH_CHECK_IDLE_NANOSECONDS = 3_000_000_000;

    /** 按当前协程及连接名取得上下文，禁止把未结束事务迁移到其他归属。 */
    final public function getTransactionContext(): TransactionContext
    {
        $cid = Coroutine::getCid();
        $name = $this->connectionName ?? 'default';
        if ($this->transactionContext === null
            || $this->transactionContext->coroutineId !== $cid
            || $this->transactionContext->connectionName !== $name) {
            if ($this->transactionContext?->isActive()) {
                throw new RuntimeError('Cannot move an active transaction to another coroutine or connection name');
            }
            $this->transactionContext = new TransactionContext($this, $cid, $name);
        }
        return $this->transactionContext;
    }

    /** 使用上下文实例身份区分连接的不同借用周期，防止旧登记影响新事务。 */
    final public function ownsTransactionContext(TransactionContext $context): bool
    {
        return $this->transactionContext === $context;
    }

    /** @internal */
    final public function __getTransactionScope(): ?TransactionScope { return $this->transactionScope; }

    /** @internal */
    final public function __setTransactionScope(?TransactionScope $scope): void { $this->transactionScope = $scope; }

    /** @deprecated 兼容旧调用；实体基准应通过 TransactionContext 登记。 */
    public function rememberTransactionBaseline(object $entity, array $baseline, callable $restore): void
    {
        $this->getTransactionContext()->rememberBaseline($entity, $baseline, $restore);
    }

    /** 统一通知上下文结束事务，同时保留原有连接事务状态接口。 */
    public function finishTransaction(bool $committed): void
    {
        if ($this->transactionContext !== null) {
            $this->transactionContext->finish($committed);
        } else {
            $this->isInTransaction = false;
        }
        if ($this->transactionScope?->depth === 0) { $this->transactionScope = null; }
    }

    public function connect(?float $timeout = null): bool
    {
        $alreadyConnected = $this->mysqlClient()?->isConnected() ?? false;
        try {
            $connected = parent::connect($timeout);
            if ($connected && !$alreadyConnected) {
                $this->lastActiveTick = hrtime(true);
            }
            return $connected;
        } catch (\Throwable $error) {
            $this->lastActiveTick = null;
            throw $error;
        }
    }

    public function close(): bool
    {
        $closed = parent::close();
        if ($closed) {
            $this->lastActiveTick = null;
            try {
                if ($this->isInTransaction) { $this->finishTransaction(false); }
            } finally {
                $this->isInTransaction = false;
                $this->transactionContext = null;
            }
        }
        return $closed;
    }

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        $this->transactionScope?->assertWire($this, $builder->getLastPrepareQuery());
        return $this->executeObserved($builder->getLastPrepareQuery(),
            fn () => parent::query($builder, $timeout), $builder);
    }

    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        $this->transactionScope?->assertWire($this, $query);
        $completion = $this->pendingCompletion;
        $this->pendingCompletion = null;
        return $this->executeObserved($query, fn () => parent::rawQuery($query, $timeout), null, $completion);
    }

    /** Synchronize successful SQL before observers, then restore its metadata after nested queries. */
    private function executeObserved(?string $sql, callable $operation, ?QueryBuilder $builder = null,
        ?\stdClass $completion = null): bool|array
    {
        $observer = $this->onQuery;
        $completed = false;
        $wrapped = function (bool|array $result, Client $client, float $startedAt) use ($observer, $sql, $builder, $completion, &$completed): void {
            // Nested operations must see the real observer rather than this operation's wrapper.
            $this->onQuery = $observer;
            $completed = true;
            $this->lastActiveTick = $result === false ? null : hrtime(true);
            $this->synchronizeTransaction($sql);
            $affectedRows = $this->lastAffectRows;
            $insertId = $this->lastInsertId;
            try {
                if ($result === true && $completion !== null) {
                    $completion->completed = true;
                    if ($completion->flags->releasesConnection()) { $this->close(); }
                }
                $deferred = [];
                $deferCountObserver = null;
                if ($builder !== null && in_array('SQL_CALC_FOUND_ROWS', $builder->getLastQueryOptions(), true)) {
                    $deferCountObserver = function (callable $read, callable $failure) use (&$deferred): void {
                        $previous = $this->deferredCountObserver;
                        $frame = (object) ['notifications' => [], 'failure' => $failure];
                        $deferred[] = $frame;
                        $this->deferredCountObserver = $frame;
                        try { $read(); }
                        finally { $this->deferredCountObserver = $previous; }
                    };
                }
                $completionError = null;
                try {
                    if ($builder !== null) { QueryExecutor::complete($builder, $result, $this, $startedAt, $deferCountObserver); }
                } catch (\Throwable $error) { $completionError = $error; }
                $this->lastAffectRows = $affectedRows;
                $this->lastInsertId = $insertId;
                if ($this->deferredCountObserver !== null) {
                    $this->deferredCountObserver->notifications[] = [$result, $startedAt, $affectedRows, $insertId];
                } else {
                    try {
                        if ($observer !== null) { $observer($result, $client, $startedAt); }
                    } catch (\Throwable $error) {
                        // The main observer originally precedes COUNT; discard its postponed notifications on failure.
                        foreach ($deferred as $frame) { ($frame->failure)(null); }
                        throw $error;
                    }
                    if ($completionError !== null) { throw $completionError; }
                    foreach ($deferred as $frame) {
                        foreach ($frame->notifications as [$countResult, $countStarted, $countAffected, $countInsert]) {
                            $this->lastAffectRows = $countAffected;
                            $this->lastInsertId = $countInsert;
                            try {
                                // The main observer may have replaced the observer for the following COUNT.
                                if ($this->onQuery !== null) { ($this->onQuery)($countResult, $this, $countStarted); }
                            } catch (\Throwable $error) {
                                ($frame->failure)($error);
                                throw $error;
                            } finally {
                                $this->lastAffectRows = $affectedRows;
                                $this->lastInsertId = $insertId;
                            }
                        }
                    }
                }
                if ($completionError !== null) { throw $completionError; }
            } finally {
                $this->lastAffectRows = $affectedRows;
                $this->lastInsertId = $insertId;
            }
        };
        $this->onQuery = $wrapped;
        try {
            $result = $operation();
            if (!$completed) {
                $this->lastActiveTick = $result === false ? null : hrtime(true);
                $this->synchronizeTransaction($sql);
            }
            return $result;
        } catch (\Throwable $error) {
            $this->lastActiveTick = null;
            // Observer errors may follow a nested transaction; they are not errors of this SQL.
            if (!$completed) {
                $this->restoreDeadlockedTransaction($error);
                throw $this->withTimeoutContext($error, $sql, $builder);
            }
            throw $error;
        } finally {
            if ($this->onQuery === $wrapped) { $this->onQuery = $observer; }
        }
    }

    /** 以协议状态同步原始事务 SQL、DDL 隐式提交及普通事务入口。 */
    private function synchronizeTransaction(?string $sql): void
    {
        $protocol = $this->mysqlClient();
        if ($protocol === null) { return; }
        $active = $protocol->inTransaction();
        $completion = TransactionSql::completion($sql);
        if ($this->isInTransaction && (!$active || $completion !== null)) {
            $this->finishTransaction($completion ?? true);
        }
        $this->isInTransaction = $active;
    }

    private function withTimeoutContext(\Throwable $error, ?string $sql, ?QueryBuilder $builder = null): \Throwable
    {
        if (!$error instanceof DriverTimeoutException) {
            return $error;
        }
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
        $context = $this->getTransactionContext();
        $version = $context->version();
        $protocol = $this->mysqlClient();
        $previousCompletion = $this->pendingCompletion;
        $completion = (object) ['flags' => $flags, 'completed' => false];
        $this->pendingCompletion = $completion;
        try {
            $success = $this->rawQuery($command . $flags->toSqlSuffix(), $timeout) === true;
        } finally {
            $this->pendingCompletion = $previousCompletion;
        }
        if ($success) {
            // Only custom/rawQuery overrides need this fallback; observers may have started a new transaction.
            if (!$completion->completed && $this->ownsTransactionContext($context) && $context->version() === $version) {
                $this->finishTransaction($command === 'COMMIT');
                $this->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            }
            if (!$completion->completed && $flags->releasesConnection() && $this->mysqlClient() === $protocol) { $this->close(); }
        }
        return $success;
    }

    private function restoreDeadlockedTransaction(\Throwable $error): void
    {
        // InnoDB 遇到死锁错误 1213 会回滚整个事务，因此同步恢复本地事务基准。
        // 其他 SQL 错误（包括锁等待超时）不能统一视为整事务回滚。
        if($error instanceof QueryException && (int) $error->getCode() === 1213){
            $this->finishTransaction(false);
        }
    }

    private function restoreDisconnectedTransaction(): bool
    {
        $protocol = $this->mysqlClient();
        if ($protocol !== null && !$protocol->isConnected()) {
            // 会话已断开，不能继续发送 ROLLBACK；关闭后清除驱动引用并恢复本地基准。
            // 连接池后续通过 beforeUse 检查淘汰此对象，不会继续复用失效会话。
            $this->close();
            $this->finishTransaction(false);
            $this->transactionContext = null;
            return true;
        }
        return false;
    }

    function gc(): void
    {
        if ($this->restoreDisconnectedTransaction()) {
            return;
        }
        if($this->isInTransaction || $this->isForceRollback){
            try {
                if($this->rollbackTransaction() === true){
                    $this->finishTransaction(false);
                }
            }catch (\Throwable $throwable){
                trigger_error($throwable->getMessage());
            }
        }

        $this->close();
        // 关闭连接后清理尚未结束的本地事务；提交结果是否已落库仍需调用方判断。
        if($this->isInTransaction){
            $this->finishTransaction(false);
        }
        $this->transactionContext = null;
    }

    function objectRestore(): void
    {
        if ($this->restoreDisconnectedTransaction()) {
            return;
        }
        if($this->isInTransaction || $this->isForceRollback){
            // 回滚失败时继续抛出异常，让连接池淘汰连接，不能把未清理的事务交给下一位使用者。
            if($this->rollbackTransaction() !== true){
                throw new RuntimeError('Failed to rollback connection during restore');
            }
            $this->finishTransaction(false);
        }
        // 本次借用已经结束，下次借出必须创建新上下文，不能沿用旧事务登记。
        $this->transactionContext = null;
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
