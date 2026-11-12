<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\Mysqli\QueryBuilder;

/** 记录实体写入时需要的选项，SQL 仍由 mysqli 构建。 */
final class EntityQueryBuilder extends QueryBuilder
{
    private bool $explicitFields = false;
    private array $duplicateColumns = [];

    public function fields(mixed $fields): QueryBuilder
    {
        $this->explicitFields = true;
        return parent::fields($fields);
    }

    public function onDuplicate(mixed $updateColumns): QueryBuilder
    {
        $this->duplicateColumns = is_array($updateColumns) ? $updateColumns : [];
        return parent::onDuplicate($updateColumns);
    }

    public function hasExplicitFields(): bool
    {
        return $this->explicitFields;
    }

    public function getDuplicateColumns(): array
    {
        return $this->duplicateColumns;
    }

    public function reset(): QueryBuilder
    {
        // 驱动构建完一条 SQL 就会重置选项，跟随同一次重置清理写入信息。
        $this->explicitFields = false;
        $this->duplicateColumns = [];
        return parent::reset();
    }
}
