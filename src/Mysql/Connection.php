<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
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

    private ?\WeakMap $transactionBaselines = null;

    /** Keep the first baseline for each live entity in this transaction. */
    public function rememberTransactionBaseline(object $entity, array $baseline, callable $restore): void
    {
        if(!$this->isInTransaction){
            return;
        }
        $this->transactionBaselines ??= new \WeakMap();
        if(!isset($this->transactionBaselines[$entity])){
            $this->transactionBaselines[$entity] = [$baseline, $restore];
        }
    }

    public function finishTransaction(bool $committed): void
    {
        $this->isInTransaction = false;
        $baselines = $this->transactionBaselines;
        $this->transactionBaselines = null;
        if(!$committed && $baselines !== null){
            foreach($baselines as $entity => [$baseline, $restore]){
                $restore($entity, $baseline);
            }
        }
    }

    public function query(QueryBuilder $builder, ?float $timeout = null): bool|array
    {
        try {
            return parent::query($builder, $timeout);
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $this->withTimeoutContext($error, $builder->getLastPrepareQuery(), $builder);
        }
    }

    public function rawQuery(string $query, ?float $timeout = null)
    {
        try {
            return parent::rawQuery($query, $timeout);
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $this->withTimeoutContext($error, $query);
        }
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
        // mysqli 5.x transaction helpers have no timeout argument; query does.
        $success = $this->rawQuery($command . $flags->toSqlSuffix(), $timeout) === true;
        if ($success && $flags->releasesConnection()) {
            $this->close();
        }
        return $success;
    }

    private function restoreDeadlockedTransaction(\Throwable $error): void
    {
        // InnoDB rolls back the entire transaction for ER_LOCK_DEADLOCK.
        // Other SQL failures (including lock wait timeout) need explicit rollback.
        if($error instanceof QueryException && (int) $error->getCode() === 1213){
            $this->finishTransaction(false);
        }
    }

    private function restoreDisconnectedTransaction(): bool
    {
        $protocol = $this->mysqlClient();
        if ($protocol !== null && !$protocol->isConnected()) {
            // A lost session cannot execute ROLLBACK. Closing clears the driver
            // reference; the pool's beforeUse check will discard this object.
            $this->close();
            $this->finishTransaction(false);
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
        // Closing a discarded connection also aborts any remaining transaction.
        if($this->isInTransaction){
            $this->finishTransaction(false);
        }
    }

    function objectRestore(): void
    {
        if ($this->restoreDisconnectedTransaction()) {
            return;
        }
        if($this->isInTransaction || $this->isForceRollback){
            // Let the pool discard the connection if rollback fails.
            if($this->rollbackTransaction() !== true){
                throw new RuntimeError('Failed to rollback connection during restore');
            }
            $this->finishTransaction(false);
        }
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