<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\Query;

/** @internal 根据显式关联声明生成的查询快照，不执行数据库查询。 */
final class RelationPlan
{
    public function __construct(
        public readonly string $kind,
        public readonly Relate $relate,
        public readonly string $tableName,
        public readonly Query $query,
        public readonly string $connectionName,
        public readonly mixed $selfValue,
    ) {}
}
