<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

/** @internal 根据显式关联声明生成的查询快照，不执行数据库查询。 */
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
    public static function foreignKey(QueryBuilder $builder, string $tableName, string $field): string
    {
        $target = $builder instanceof EntityQueryBuilder
            ? $builder->relationTableTarget($tableName)
            : TableReference::resolve($tableName, '')['target'];
        if ($target === null) {
            throw new \InvalidArgumentException('Relation requires an identifiable target table');
        }
        return $target . '.' . TableReference::identifier($field);
    }

    /** 只检查 FastDb 自己负责的分页与一对一基数规则。 */
    public static function checkPagination(QueryBuilder $query, RelationType $type, bool $preload = false): void
    {
        if (in_array('SQL_CALC_FOUND_ROWS', $query->getQueryOptions(), true)) {
            throw new RuntimeError('Relation does not support withTotalCount()');
        }
        if ($query instanceof EntityQueryBuilder && $query->getLimitRowCount() !== null
            && ($preload || $type === RelationType::One)) {
            // 一对一要检测重复记录，批量预加载不能按整批截断。
            throw new RuntimeError($preload
                ? 'Relation preload does not support limit()'
                : 'One-to-one relation does not support limit()');
        }
    }

    /** 按需加载及预加载的缓存查询共用关联范围、字段和单条限制规则。 */
    public static function buildLookup(QueryBuilder $query, string $tableName, Relate $relate,
        mixed $selfValue, ?array $fields): QueryBuilder
    {
        $foreignKey = self::foreignKey($query, $tableName, $relate->targetProperty);
        $query->groupExistingWhere();
        if ($selfValue === null) {
            $query->whereNull($foreignKey);
        } else {
            $query->where($foreignKey, $selfValue);
        }
        return $query->get($tableName, $relate->type === RelationType::One ? 2 : null, $fields);
    }
}
