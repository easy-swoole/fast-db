<?php

namespace EasySwoole\FastDb\Mysql;

/**
 * 管理一次连接借用期间的实体比较基准，不负责发送事务 SQL。
 * 协程 ID、连接名及实际连接实例共同确定归属，避免多个数据库或连接复用时串用登记。
 */
final class TransactionContext
{
    // 弱引用不阻止连接销毁；连接本身负责持有当前有效的上下文。
    private \WeakReference $connection;
    // 已释放的实体无需恢复，使用 WeakMap 避免仅因事务登记而延长实体生命周期。
    private ?\WeakMap $baselines = null;

    public function __construct(
        Connection $connection,
        public readonly int $coroutineId,
        public readonly string $connectionName,
    ) {
        $this->connection = \WeakReference::create($connection);
    }

    /** 检查实际连接实例，不能仅凭相同的连接名判断归属。 */
    public function belongsTo(Connection $connection): bool
    {
        return $this->connection->get() === $connection;
    }

    /** 连接被重新借用后，旧上下文即使仍被引用，也不再代表当前事务。 */
    public function isActive(): bool
    {
        $connection = $this->connection->get();
        return $connection !== null && $connection->ownsTransactionContext($this) && $connection->isInTransaction;
    }

    /**
     * 每个实体只登记本事务中的首次基准，多次 update 不覆盖最初状态。
     * 恢复回调应避免捕获实体强引用，以保留 WeakMap 自动释放实体的效果。
     */
    public function rememberBaseline(object $entity, array $baseline, callable $restore): void
    {
        if (!$this->isActive()) {
            return;
        }
        $this->baselines ??= new \WeakMap();
        if (!isset($this->baselines[$entity])) {
            $this->baselines[$entity] = [$baseline, $restore];
        }
    }

    /**
     * 提交时丢弃登记；回滚清理时恢复比较基准，保留实体当前属性值以便重试。
     * 该方法只处理本地状态；断线后的清理不能证明服务端提交一定没有发生。
     */
    public function finish(bool $committed): void
    {
        $connection = $this->connection->get();
        if ($connection !== null && $connection->ownsTransactionContext($this)) {
            $connection->isInTransaction = false;
        }
        // 先移出并清空登记，避免重复结束事务时再次执行相同恢复回调。
        $baselines = $this->baselines;
        $this->baselines = null;
        if (!$committed && $baselines !== null) {
            foreach ($baselines as $entity => [$baseline, $restore]) {
                $restore($entity, $baseline);
            }
        }
    }
}
