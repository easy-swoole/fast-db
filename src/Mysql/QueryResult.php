<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\QueryBuilder;

class QueryResult
{
    protected float $endTime;
    protected float $startTime;
    // 单调时钟仅用于计算耗时，不能作为日期时间使用。
    protected int|float $startTick;
    protected float $duration = 0.0;
    protected mixed $result = null;
    protected ?\Throwable $exception = null;
    protected Connection $connection;

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

    /**
     * @return Connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param Connection $connection
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
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
