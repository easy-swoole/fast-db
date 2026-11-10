<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use Swoole\Coroutine;
use EasySwoole\FastDb\Internal\TransactionSql;
use EasySwoole\FastDb\Internal\ExecutionFrame;
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
    private ?ExecutionFrame $executionFrame = null;
    private ?\Closure $userObserver = null;
    // 单调时钟记录最后一次成功通信的结束时间，归还连接时保留，关闭或失败时清除。
    private int|float|null $lastActiveTick = null;
    private const HEALTH_CHECK_IDLE_NANOSECONDS = 3_000_000_000;

    public function __construct(\EasySwoole\Mysqli\Config $config)
    {
        parent::__construct($config);
        // 无捕获共享 dispatcher 不持有连接；用户替换回调也无需再包裹每条 SQL。
        static $dispatcher = null;
        $this->onQuery = $dispatcher ??= static function (bool|array $result, Client $client, float $startedAt): void {
            $client->observeResult($result, $startedAt);
        };
    }

    public function onQuery(callable $callback): static
    {
        $this->userObserver = \Closure::fromCallable($callback);
        return $this;
    }

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
        return $this->executeQuery($builder, $builder->getLastPrepareQuery(), $timeout);
    }

    public function rawQuery(string $query, ?float $timeout = null): bool|array
    {
        return $this->executeQuery(null, $query, $timeout);
    }

    /** @internal Facade 显式传帧；自定义 query/rawQuery 也只有这一处完成回退。 */
    final public function runFrame(ExecutionFrame $frame, callable $operation): QueryResult
    {
        $previous = $this->executionFrame;
        $this->executionFrame = $frame;
        try {
            $value = $operation($frame);
            if (!$frame->completed) { $this->finishFrame($frame, $value, false); }
            return $frame->result;
        } catch (\Throwable $error) {
            $frame->fail($error);
            try { $frame->complete($this); } catch (\Throwable) {}
            throw $error;
        } finally {
            $this->executionFrame = $previous;
        }
    }

    private function executeQuery(?QueryBuilder $builder, ?string $sql, ?float $timeout): bool|array
    {
        $this->transactionScope?->assertWire($this, $sql);
        $frame = $this->executionFrame;
        if ($frame === null || !$frame->accepts($builder, $sql)) {
            $frame = new ExecutionFrame($builder, $sql);
            $frame->result->setConnectionName($this->connectionName ?? 'default');
        }
        $previous = $this->executionFrame;
        $this->executionFrame = $frame;
        $frame->start();
        $frame->observer = $this->userObserver;
        try {
            return $builder === null ? parent::rawQuery($sql, $timeout) : parent::query($builder, $timeout);
        } catch (\Throwable $error) {
            $this->lastActiveTick = null;
            if (!$frame->completed) {
                $this->restoreDeadlockedTransaction($error);
                $error = $this->withTimeoutContext($error, $sql, $builder);
            }
            $frame->fail($error);
            try { $frame->complete($this); } catch (\Throwable) {}
            throw $error;
        } finally {
            $this->executionFrame = $previous;
        }
    }

    /** Client 唯一的成功入口；已完成的外层帧不能接收旁路/重入操作的完成通知。 */
    private function observeResult(bool|array $value, float $startedAt): void
    {
        $frame = $this->executionFrame;
        if ($frame === null || !$frame->started || $frame->completed) {
            if ($this->userObserver !== null) { ($this->userObserver)($value, $this, $startedAt); }
            return;
        }
        $frame->driverStartedAt = $startedAt;
        $this->finishFrame($frame, $value, true);
    }

    /** 冻结结果和状态后再公开通知；COUNT 先读，低层通知仍在主查询观察回调之后。 */
    private function finishFrame(ExecutionFrame $frame, bool|array $value, bool $fromDriver): void
    {
        $frame->succeed($this, $value);
        try {
            if ($fromDriver) {
                $this->lastActiveTick = $value === false ? null : hrtime(true);
                $this->synchronizeTransaction($frame->sql ?? $frame->result->getQueryBuilder()?->getLastPrepareQuery());
            } else {
                $this->finishCustomControl($frame, $value);
            }
            if ($value === true && $frame->completionFlags?->releasesConnection()) { $this->close(); }
            $completionError = null;
            if ($frame->wantsTotal()) {
                $count = $frame->count = new ExecutionFrame(null, 'SELECT FOUND_ROWS() as count', deferObserver: true);
                $count->result->setConnectionName($frame->result->getConnectionName());
                $count->start();
                try {
                    $this->runFrame($count, fn () => $this->rawQuery('SELECT FOUND_ROWS() as count'));
                    $frame->result->setTotalResult($count->result);
                } catch (\Throwable $error) { $completionError = $error; }
            }
            try { $frame->complete($this); }
            catch (\Throwable $error) { $completionError ??= $error; }
            if ($fromDriver && !$frame->deferObserver) {
                $this->restoreMetadata($frame);
                try { $this->notifyObserver($frame); }
                catch (\Throwable $error) {
                    $frame->invalidateTotal();
                    throw $error;
                }
            }
            if ($completionError !== null) { throw $completionError; }
            if ($frame->count !== null) {
                try { $this->notifyObserver($frame->count, true); }
                catch (\Throwable $error) {
                    $frame->invalidateTotal($error);
                    throw $error;
                }
            }
        } finally {
            $this->restoreMetadata($frame);
        }
    }

    private function notifyObserver(ExecutionFrame $frame, bool $useCurrent = false): void
    {
        $this->restoreMetadata($frame);
        $observer = $useCurrent ? $this->userObserver : $frame->observer;
        if ($observer !== null) {
            $observer($frame->result->getResult(), $this, $frame->driverStartedAt);
        }
    }

    private function restoreMetadata(ExecutionFrame $frame): void
    {
        $this->lastAffectRows = $frame->result->getAffectedRows();
        $this->lastInsertId = $frame->result->getInsertId();
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
        return $this->executeControl($flags->toSql(), 'BEGIN', null, $timeout);
    }

    public function commitTransaction(TransactionCompletionFlags $flags, ?float $timeout = null): bool
    {
        return $this->executeControl('COMMIT' . $flags->toSqlSuffix(), 'COMMIT', $flags, $timeout);
    }

    public function rollbackTransaction(TransactionCompletionFlags $flags = TransactionCompletionFlags::NoChainNoRelease, ?float $timeout = null): bool
    {
        return $this->executeControl('ROLLBACK' . $flags->toSqlSuffix(), 'ROLLBACK', $flags, $timeout);
    }

    private function executeControl(string $sql, string $command, ?TransactionCompletionFlags $flags, ?float $timeout): bool
    {
        $frame = $this->executionFrame;
        if ($frame === null || !$frame->accepts(null, $sql)) {
            $frame = new ExecutionFrame(null, $sql);
            $frame->result->setConnectionName($this->connectionName ?? 'default');
        }
        $frame->transactionCommand = $command;
        $frame->completionFlags = $flags;
        $frame->transactionContext = $this->getTransactionContext();
        $frame->transactionVersion = $frame->transactionContext->version();
        $frame->transactionWasActive = $this->isInTransaction;
        $frame->start();
        return $this->runFrame($frame, fn () => $this->rawQuery($sql, $timeout))->getResult() === true;
    }

    /** 自定义连接没有协议成功入口时，仅同步本帧原来拥有的事务代次。 */
    private function finishCustomControl(ExecutionFrame $frame, bool|array $value): void
    {
        $context = $frame->transactionContext;
        if ($value !== true || $context === null || !$this->ownsTransactionContext($context)
            || $context->version() !== $frame->transactionVersion) { return; }
        if ($frame->transactionCommand === 'BEGIN') {
            if ($frame->transactionWasActive) { $this->finishTransaction(true); }
            $this->isInTransaction = true;
        } else {
            $this->finishTransaction($frame->transactionCommand === 'COMMIT');
            $this->isInTransaction = in_array($frame->completionFlags,
                [TransactionCompletionFlags::Chain, TransactionCompletionFlags::ChainNoRelease], true);
        }
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
            if ($this->isInTransaction) { $this->finishTransaction(false); }
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
                $this->rollbackTransaction();
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
