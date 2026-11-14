<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

/** 记录实体写入时需要的选项，SQL 仍由 mysqli 构建。 */
final class EntityQueryBuilder extends QueryBuilder
{
    private ?array $duplicateColumns = null;

    public function onDuplicate(mixed $updateColumns): QueryBuilder
    {
        parent::onDuplicate($updateColumns);
        $this->duplicateColumns = $updateColumns;
        return $this;
    }

    // mysqli 的前缀会跨查询保留，预加载使用它限定默认投影。
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
        // 驱动构建完一条 SQL 就会重置选项，跟随同一次重置清理写入信息。
        $this->duplicateColumns = null;
        return parent::reset();
    }
}
