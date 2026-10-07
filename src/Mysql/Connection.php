<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\Client;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Exception\Exception as QueryException;
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

    public function query(QueryBuilder $builder)
    {
        try {
            return parent::query($builder);
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $error;
        }
    }

    public function rawQuery(string $query)
    {
        try {
            return parent::rawQuery($query);
        } catch (\Throwable $error) {
            $this->restoreDeadlockedTransaction($error);
            throw $error;
        }
    }

    private function restoreDeadlockedTransaction(\Throwable $error): void
    {
        // InnoDB rolls back the entire transaction for ER_LOCK_DEADLOCK.
        // Other SQL failures (including lock wait timeout) need explicit rollback.
        if($error instanceof QueryException && (int) $error->getCode() === 1213){
            $this->finishTransaction(false);
        }
    }

    function gc(): void
    {
        if($this->isInTransaction || $this->isForceRollback){
            try {
                if($this->mysqlClient()->rollback() === true){
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
        if($this->isInTransaction || $this->isForceRollback){
            // Let the pool discard the connection if rollback fails.
            if($this->mysqlClient()->rollback() !== true){
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