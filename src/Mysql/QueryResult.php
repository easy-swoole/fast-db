<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\QueryBuilder;

class QueryResult
{
    protected float $endTime;
    protected float $startTime;
    protected mixed $result;
    protected Connection $connection;

    protected ?QueryBuilder $queryBuilder = null;

    protected ?string $rawSql = null;

    function __construct(float $startTime)
    {
        $this->startTime = $startTime;
        $this->endTime = microtime(true);
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
