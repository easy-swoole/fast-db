<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Attributes\RelationType;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;
use InvalidArgumentException;

/** @internal 保存一次关联查询的配置，负责追加 FastDb 生成的关联条件。 */
final class RelationPlan
{
    public function __construct(
        public readonly Relate $relate,
        public readonly string $tableName,
        public readonly QueryBuilder $builder,
        public readonly ?array $columns,
        public readonly bool $returnAsArray,
        public readonly string $connectionName,
        public readonly mixed $selfValue,
        public readonly ?AbstractEntity $target = null,
    ) {}

    /** 限定 FastDb 自己添加的外键条件，调用方投影和附加条件保持原样。 */
    public function foreignKey(): string
    {
        $target = $this->builder->resolveTableTarget($this->tableName);
        if ($target === null) {
            throw new InvalidArgumentException('Relation requires an identifiable target table');
        }
        return $target . '.' . TableReference::identifier($this->relate->targetProperty);
    }

    /** 只检查 FastDb 自己负责的分页与一对一基数规则。 */
    public function checkPagination(bool $preload = false): void
    {
        if (in_array('SQL_CALC_FOUND_ROWS', $this->builder->getQueryOptions(), true)) {
            throw new RuntimeError('Relation does not support withTotalCount()');
        }
        if ($this->builder->getLimitRowCount() !== null && ($preload || $this->relate->type === RelationType::One)) {
            // 一对一要检测重复记录，批量预加载不能按整批截断。
            throw new RuntimeError($preload
                ? 'Relation preload does not support limit()'
                : 'One-to-one relation does not support limit()');
        }
    }

    /** 预加载传入构造器副本，保留原构造器用于生成批量 SQL。 */
    public function buildLookup(QueryBuilder $query): QueryBuilder
    {
        $foreignKey = $this->foreignKey();
        $query->groupExistingWhere();
        if ($this->selfValue === null) {
            $query->whereNull($foreignKey);
        } else {
            $query->where($foreignKey, $this->selfValue);
        }
        $limit = $this->relate->type === RelationType::One ? 2 : null;
        return $query->get($this->tableName, $limit, $this->columns);
    }
}
