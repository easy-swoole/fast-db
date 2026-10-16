<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use Swoole\Coroutine;
use EasySwoole\FastDb\Internal\TransactionSql;
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
    }

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        try {
            $result = parent::query($builder, $timeout);
            $this->synchronizeTransaction($builder->getLastPrepareQuery());
            return $result;
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $this->withTimeoutContext($error, $builder->getLastPrepareQuery(), $builder);
        }
    }

    public function rawQuery(string $query, ?float $timeout = null)
    {
        try {
            $result = parent::rawQuery($query, $timeout);
            $this->synchronizeTransaction($query);
            return $result;
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $this->withTimeoutContext($error, $query);
        }
    }

    /** 仅包装驱动超时异常，保留原异常链，并复制构建器用于事后定位 SQL。 */
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
        $success = $this->rawQuery($command . $flags->toSqlSuffix(), $timeout) === true;
        if ($success) {
            // CHAIN 结束旧事务后立即开始新事务，协议的布尔状态不足以表达此边界。
            if ($context->version() === $version) { $this->finishTransaction($command === 'COMMIT'); }
            $this->isInTransaction = in_array($flags, [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
            if ($flags->releasesConnection()) { $this->close(); }
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
        try{
            $this->mysqlClient()->query('select 1');
            return true;
        }catch (\Throwable $throwable){
            return false;
        }
    }

    function intervalCheck(): bool
    {
        try{
            $this->mysqlClient()->query('select 1');
            return true;
        }catch (\Throwable $throwable){
            return false;
        }
    }
}