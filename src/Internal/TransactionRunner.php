<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;
use EasySwoole\Mysqli\Transaction\TransactionStartFlags;

/** 管理回调事务的拥有权、加入规则与失败清理，不保存额外事务历史。 */
final class TransactionRunner
{
    /** 绑定当前实际连接的回调事务；同连接嵌套加入原事务，不创建 savepoint。 */
    public static function run(FastDb $db, Connection $client, callable $callback, TransactionStartFlags $flags = TransactionStartFlags::None,
        ?float $timeout = null): mixed
    {
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new \InvalidArgumentException('Transaction timeout must be finite and greater than zero');
        }
        $context = $db->getTransactionContext($client);
        $scope = $client->__getTransactionScope();
        if ($scope !== null && !$scope->isCurrent($client, false)) {
            if ($scope->depth > 0) { throw new RuntimeError('The managed transaction connection or generation has changed'); }
            $client->__setTransactionScope(null);
            $scope = null;
        }
        $owner = !$client->isInTransaction;
        if (!$owner && ($flags !== TransactionStartFlags::None || $timeout !== null)) {
            throw new \InvalidArgumentException('A joined transaction cannot change start flags or timeout');
        }
        $scope ??= new TransactionScope($context);
        if ($scope->unusable) { throw new RuntimeError('Transaction cleanup failed; close or recycle the connection before reuse'); }
        if ($scope->rollbackOnly) { throw new RuntimeError('Transaction is marked rollback-only'); }
        $client->__setTransactionScope($scope);
        $scope->depth++;
        $left = false;
        try {
            if ($owner) {
                $scope->authorizeBegin($flags->toSql());
                try {
                    if (!$db->begin($client, $flags, $timeout)) { throw new RuntimeError('Failed to begin scoped transaction'); }
                } finally { $scope->clearPermit(); }
            }
            if (!$scope->isCurrent($client)) { throw new RuntimeError('The scoped transaction is no longer active'); }
            if ($scope->rollbackOnly) { throw new RuntimeError('Transaction is marked rollback-only'); }
            $value = $callback($db);
            if (!$scope->isCurrent($client)) { throw new RuntimeError('The scoped transaction connection or generation changed during the callback'); }
            if ($scope->rollbackOnly) { throw new RuntimeError('Transaction is marked rollback-only by an inner scope'); }
            // 回调已经退出；拥有者结束事务后，旧作用域不再限制提交回调中开启的新事务。
            $scope->depth--;
            $left = true;
            if ($owner) {
                if (!$db->commit($client, TransactionCompletionFlags::NoChainNoRelease, $timeout)) {
                    throw new RuntimeError('Failed to commit scoped transaction');
                }
                if ($scope->isCurrent($client)) { throw new RuntimeError('The scoped transaction was not completed by commit'); }
            }
            return $value;
        } catch (\Throwable $error) {
            $scope->rollbackOnly = true;
            if (!$left) { $scope->depth--; $left = true; }
            if ($owner) { self::rollbackOwnedScope($db, $client, $scope, $timeout); }
            throw $error;
        } finally {
            if (!$left) { $scope->depth--; }
            if ($client->__getTransactionScope() === $scope && $scope->depth === 0
                && (!$scope->rollbackOnly || !$scope->isCurrent($client))) {
                $client->__setTransactionScope(null);
            }
        }
    }

    /** 清理仍属于原代次的事务，保留业务/提交异常，绝不回滚观察回调开启的新事务。 */
    private static function rollbackOwnedScope(FastDb $db, Connection $client, TransactionScope $scope, ?float $timeout): void
    {
        if (!$scope->isCurrent($client)) { return; }
        try {
            if (!$db->rollback($client, TransactionCompletionFlags::NoChainNoRelease, $timeout)) {
                throw new RuntimeError('Failed to rollback scoped transaction');
            }
        } catch (\Throwable) {
            if ($scope->isCurrent($client)) {
                try { $client->close(); } catch (\Throwable) {}
                if ($scope->isCurrent($client)) { $scope->unusable = true; }
            }
        }
    }

}
