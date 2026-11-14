<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\Mysqli\QueryBuilder;

/** @internal 只保存 SQL 日志所需的数据，不持有查询结果或连接。 */
final class SqlSnapshot
{
    private readonly ?string $prepareQuery;
    private readonly array $bindParams;
    private readonly ?QueryBuilder $logBuilder;

    public function __construct(?string $rawSql = null, ?QueryBuilder $builder = null)
    {
        $this->prepareQuery = $builder === null ? $rawSql : $builder->getLastPrepareQuery();
        $this->bindParams = $builder?->getLastBindParams() ?? [];
        // 绑定参数的日志 SQL 由 mysqli 按构建时的引号规则展开。
        $this->logBuilder = $this->bindParams === [] ? null : clone $builder;
    }

    public function getLastQuery(): ?string
    {
        return $this->logBuilder !== null ? $this->logBuilder->getLastQuery() : $this->prepareQuery;
    }

    public function getLastPrepareQuery(): ?string
    {
        return $this->prepareQuery;
    }

    public function getLastBindParams(): array
    {
        return $this->bindParams;
    }
}
