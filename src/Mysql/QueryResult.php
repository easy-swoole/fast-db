<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\QueryBuilder;

class QueryResult
{
    protected mixed $result;
    protected Connection $connection;

    protected ?QueryBuilder $queryBuilder;

    protected ?string $rawSql;

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
        return $this->rawSql;
    }

    /**
     * @param string|null $rawSql
     */
    public function setRawSql(?string $rawSql): void
    {
        $this->rawSql = $rawSql;
    }
}