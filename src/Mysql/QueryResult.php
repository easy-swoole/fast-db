<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\FastDb\Internal\SqlSnapshot;
use EasySwoole\Mysqli\QueryBuilder;

/** 查询完成时的只读结果，日志 SQL 使用本次构建时的参数和引号规则。 */
final class QueryResult
{
    private readonly SqlSnapshot $sql;

    public function __construct(
        private readonly float $startTime,
        private readonly float $endTime,
        private readonly float $duration,
        private readonly string $connectionName,
        private readonly mixed $result = null,
        private readonly ?\Throwable $exception = null,
        private readonly int|string|null $affectedRows = null,
        private readonly int|string|null $insertId = null,
        private readonly ?string $rawSql = null,
        ?QueryBuilder $builder = null,
        private readonly ?self $totalResult = null,
    ) {
        $this->sql = new SqlSnapshot($rawSql, $builder);
    }

    /** @internal 查询栈共享日志快照，不保留结果行。 */
    public function sqlSnapshot(): SqlSnapshot
    {
        return $this->sql;
    }

    public function getLastQuery(): ?string
    {
        return $this->sql->getLastQuery();
    }

    public function getLastPrepareQuery(): ?string
    {
        return $this->sql->getLastPrepareQuery();
    }

    public function getLastBindParams(): array
    {
        return $this->sql->getLastBindParams();
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getTotalResult(): ?self
    {
        return $this->totalResult;
    }

    public function getTotalCount(): ?int
    {
        $value = $this->totalResult?->getResultOne()['count'] ?? null;
        return $value === null ? null : (int) $value;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function getResult(): mixed
    {
        return $this->result;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function getAffectedRows(): int|string|null
    {
        return $this->affectedRows;
    }

    public function getInsertId(): int|string|null
    {
        return $this->insertId;
    }

    public function getRawSql(): ?string
    {
        return $this->rawSql;
    }

    public function getEndTime(): float
    {
        return $this->endTime;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getResultOne(): mixed
    {
        return is_array($this->result) ? ($this->result[0] ?? null) : null;
    }
}
