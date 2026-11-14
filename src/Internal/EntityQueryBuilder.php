<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

/** 记录实体操作需要读取的构建选项，SQL 仍由 mysqli 构建。 */
final class EntityQueryBuilder extends QueryBuilder
{
    private ?array $duplicateColumns = null;
    private ?int $limitRowCount = null;
    private bool $hasGroupBy = false;

    public function limit(int $one, ?int $two = null): QueryBuilder
    {
        $this->limitRowCount = $two ?? $one;
        return parent::limit($one, $two);
    }

    public function getLimitRowCount(): ?int
    {
        return $this->limitRowCount;
    }

    public function groupBy(mixed $groupByField): QueryBuilder
    {
        $this->hasGroupBy = true;
        return parent::groupBy($groupByField);
    }

    public function hasGroupBy(): bool
    {
        return $this->hasGroupBy;
    }

    public function onDuplicate(mixed $updateColumns): QueryBuilder
    {
        parent::onDuplicate($updateColumns);
        $this->duplicateColumns = $updateColumns;
        return $this;
    }

    // mysqli 的前缀会跨查询保留，关联查询用它限定框架生成的外键条件。
    private mixed $tablePrefix = '';

    public function setPrefix(mixed $prefix = ''): QueryBuilder
    {
        parent::setPrefix($prefix);
        $this->tablePrefix = $prefix;
        return $this;
    }

    public function relationTableTarget(string $tableName): ?string
    {
        return TableReference::resolve($tableName, $this->tablePrefix)['target'];
    }

    public function getDuplicateColumns(): ?array
    {
        return $this->duplicateColumns;
    }

    public function reset(): QueryBuilder
    {
        // 驱动构建完一条 SQL 就会重置选项，这些标记也只属于当前查询。
        $this->duplicateColumns = null;
        $this->limitRowCount = null;
        $this->hasGroupBy = false;
        return parent::reset();
    }
}
