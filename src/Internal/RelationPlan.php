<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\Mysqli\QueryBuilder;

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
    ) {}

    /** 按需加载及预加载的缓存查询共用关联范围、字段和单条限制规则。 */
    public static function buildLookup(QueryBuilder $query, string $tableName, Relate $relate,
        mixed $selfValue, ?array $fields): QueryBuilder
    {
        return $query->groupExistingWhere()->where($relate->targetProperty, $selfValue)
            ->get($tableName, $relate->type === RelationType::One ? 2 : null, $fields);
    }
}
