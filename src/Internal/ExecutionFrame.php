<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\FastDb\Mysql\TransactionContext;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\Transaction\TransactionCompletionFlags;

/** 集中保存单次执行状态；结果不直接引用执行帧或连接，完成回调在调用前解除登记。 */
final class ExecutionFrame
{
    public readonly QueryResult $result;
    public bool $started = false;
    public bool $completed = false;
    public ?self $count = null;
    public ?\Throwable $failure = null;
    public ?string $transactionCommand = null;
    public ?TransactionCompletionFlags $completionFlags = null;
    public ?TransactionContext $transactionContext = null;
    public int $transactionVersion = 0;
    public bool $transactionWasActive = false;
    private ?\Closure $onComplete;

    public function __construct(
        public readonly ?QueryBuilder $builder = null,
        public readonly ?string $sql = null,
        ?callable $onComplete = null,
        public readonly bool $captureTotal = false,
    ) {
        $this->result = new QueryResult(microtime(true));
        $this->result->setRawSql($sql);
        $this->onComplete = $onComplete === null ? null : \Closure::fromCallable($onComplete);
    }

    public function accepts(?QueryBuilder $builder, ?string $sql): bool
    {
        return $this->started && !$this->completed && $this->builder === $builder
            && ($builder !== null || $this->sql === $sql);
    }

    /** 工厂及作用域预检完成后才授权本次发送，重入构建不能提前消费外层帧。 */
    public function start(): void { $this->started = true; }

    public function succeed(Connection $client, bool|array $value): void
    {
        if ($this->completed) { return; }
        $this->completed = true;
        $this->result->setResult($value);
        $this->result->setAffectedRows($client->getLastAffectRows());
        $this->result->setInsertId($client->getLastInsertId());
        $this->snapshot();
        $this->result->finishTiming();
    }

    public function fail(\Throwable $error): void
    {
        $this->failure ??= $error;
        if (!$this->completed) {
            $this->completed = true;
            $this->result->setException($error);
            $this->snapshot();
            $this->result->finishTiming();
        }
    }

    /** 在公开查询回调前取出并清空，保证完成回调只调用一次并及时解除捕获对象的登记。 */
    public function complete(Connection $client): void
    {
        $callback = $this->onComplete;
        $this->onComplete = null;
        if ($callback !== null) { $callback($this->result, $client); }
    }

    public function wantsTotal(): bool
    {
        return $this->captureTotal && $this->builder !== null
            && in_array('SQL_CALC_FOUND_ROWS', $this->result->getQueryBuilder()->getLastQueryOptions(), true);
    }

    private function snapshot(): void
    {
        if ($this->captureTotal && $this->builder !== null) { $this->result->setQueryBuilder(clone $this->builder); }
    }
}
