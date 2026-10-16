<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\QueryBuilder;

/** 保存单次执行的结果快照，不持有数据库连接，也不承担事务登记职责。 */
class QueryResult
{
    protected float $endTime;
    protected float $startTime;
    // 单调时钟仅用于计算耗时，不能作为日期时间使用。
    protected int|float $startTick;
    protected float $duration = 0.0;
    protected mixed $result = null;
    protected ?\Throwable $exception = null;
    // 执行连接名的快照，不随日志回调或后续操作切换数据库而改变。
    protected string $connectionName;
    // null 表示未取得元数据，不能与成功执行但影响 0 行混淆。
    // 超过 PHP 整数范围的无符号数保留为十进制字符串，避免精度丢失。
    protected int|string|null $affectedRows = null;
    protected int|string|null $insertId = null;

    protected ?QueryBuilder $queryBuilder = null;

    protected ?string $rawSql = null;

    function __construct(float $startTime, int|float|null $startTick = null)
    {
        $this->startTime = $startTime;
        $this->endTime = microtime(true);
        $this->startTick = $startTick ?? hrtime(true);
    }

    /** 结束计时；墙上时间用于日志定位，单调时钟用于测量耗时。 */
    public function finishTiming(): void
    {
        $endTick = hrtime(true);
        $this->endTime = microtime(true);
        $this->duration = ($endTick - $this->startTick) / 1_000_000_000;
    }

    /** 返回最近一次 finishTiming() 记录的耗时，单位为秒。 */
    public function getDuration(): float
    {
        return $this->duration;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function setException(?\Throwable $exception): void
    {
        $this->exception = $exception;
    }

    /**
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * @param mixed $result
     */
    public function setResult(mixed $result): void
    {
        $this->result = $result;
    }

    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    public function setConnectionName(string $connectionName): void
    {
        $this->connectionName = $connectionName;
    }

    /** 返回本次执行的影响行数快照，不受后续查询或日志回调中的 SQL 影响。 */
    public function getAffectedRows(): int|string|null
    {
        return $this->affectedRows;
    }

    public function setAffectedRows(int|string|null $affectedRows): void
    {
        $this->affectedRows = $affectedRows;
    }

    /** 返回本次执行的自增 ID 快照；无自增 ID 时通常为 0，未取得结果时为 null。 */
    public function getInsertId(): int|string|null
    {
        return $this->insertId;
    }

    public function setInsertId(int|string|null $insertId): void
    {
        $this->insertId = $insertId;
    }

    /**
     * @return QueryBuilder|null
     */
    public function getQueryBuilder(): ?QueryBuilder
    {
        return $this->queryBuilder;
    }

    /**
     * @param QueryBuilder|null $queryBuilder
     */
    public function setQueryBuilder(?QueryBuilder $queryBuilder): void
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * @return string|null
     */
    public function getRawSql(): ?string
    {
        return $this->rawSql ?? null;
    }

    /**
     * @param string|null $rawSql
     */
    public function setRawSql(?string $rawSql): void
    {
        $this->rawSql = $rawSql;
    }

    /**
     * @return float
     */
    public function getEndTime(): float
    {
        return $this->endTime;
    }

    /**
     * @param float $endTime
     */
    public function setEndTime(float $endTime): void
    {
        $this->endTime = $endTime;
    }

    /**
     * @return float
     */
    public function getStartTime(): float
    {
        return $this->startTime;
    }

    /**
     * @param float $startTime
     */
    public function setStartTime(float $startTime): void
    {
        $this->startTime = $startTime;
    }

    public function getResultOne(): mixed
    {
        if (is_array($this->result)) {
            return $this->result[0] ?? null;
        }

        return null;
    }
}
