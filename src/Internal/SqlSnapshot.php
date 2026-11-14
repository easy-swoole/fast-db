<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\CompiledQuery;

/** @internal 只保存 SQL 日志所需的数据，不持有查询结果或连接。 */
final class SqlSnapshot
{
    private readonly ?string $rawSql;
    private readonly ?CompiledQuery $compiledQuery;

    public function __construct(?string $rawSql = null, ?QueryBuilder $builder = null)
    {
        $this->rawSql = $builder === null ? $rawSql : null;
        // 只保存本次发布的编译结果，展开日志时沿用编译时的引号规则。
        $this->compiledQuery = $builder?->getLastCompiledQuery();
    }

    public function getLastQuery(): ?string
    {
        return $this->compiledQuery?->toDebugSql() ?? $this->rawSql;
    }

    public function getLastPrepareQuery(): ?string
    {
        return $this->compiledQuery?->sql ?? $this->rawSql;
    }

    public function getLastBindParams(): array
    {
        return $this->compiledQuery?->parameters ?? [];
    }
}
