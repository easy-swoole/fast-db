<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

/** 仅在显式预加载时创建；计划、分桶及临时父模型只在当前批次内存活。 */
final class RelationPreloader
{
    private const BATCH_SIZE = 250;

    public function load(AbstractEntity $source, array $parents, array $rows, array $relations,
        bool $asArray, string $connectionName, array $hiddenFields, callable $execute): array
    {
        if ($parents === []) { return $parents; }
        $db = FastDb::getInstance();
        $selected = $db->selectConnection();
        try {
            $groups = $this->collectPlans($source, $parents, $rows, $relations, $asArray, $connectionName, $hiddenFields);
            return $this->attachResults($parents, $groups, $asArray, $execute);
        } finally {
            $db->selectConnection($selected);
        }
    }

    /** 逐父解析显式配置，按实际 SQL 和参数合并可共享的查询。 */
    private function collectPlans(AbstractEntity $source, array $parents, array $rows, array $relations,
        bool $asArray, string $connectionName, array $hiddenFields): array
    {
        $db = FastDb::getInstance();
        $groups = [];
        $position = 0;
        $class = $source::class;
        foreach ($rows as $row) {
            if ($asArray) {
                // 临时父模型的初始化和字段转换沿用父查询实际连接。
                $db->selectConnection($connectionName);
                $parent = new $class($row);
            } else {
                $parent = $parents[$position];
            }
            foreach ($relations as $method) {
                $db->selectConnection($connectionName);
                if ($asArray && array_key_exists($method, $parents[$position])) {
                    throw new RuntimeError('Relation name conflicts with a selected field: ' . $method);
                }
                $plan = $parent->__relationPlan($method);
                $self = $plan->relate->selfProperty;
                if (!array_key_exists($self, $row)) {
                    throw new RuntimeError('Preloading requires the parent relation field to be selected under its original name: ' . $self);
                }
                if (!$asArray && in_array($self, $hiddenFields, true)) {
                    throw new RuntimeError('An entity relation field cannot be hidden while preloading: ' . $self);
                }
                $value = $plan->selfValue;
                if ($value !== null && !is_int($value) && !is_string($value)) {
                    throw new RuntimeError('Parent relation fields must contain an integer, string or null');
                }
                $db->selectConnection($plan->connectionName);
                $table = $plan->tableName;
                $builder = $plan->builder;
                $columns = $plan->columns;
                $preview = (clone $builder)->get($table, null, $columns);
                // 数组结果直接附加关联数据，不需要生成用于实体缓存匹配的查询。
                $lookup = $asArray ? null : RelationPlan::buildLookup(clone $builder, $table, $plan->relate,
                    $value, $columns);
                $key = hash('sha256', serialize([$plan->connectionName, $plan->relate->type,
                    $plan->relate->targetEntity, $plan->relate->targetProperty, $table, $plan->returnAsArray,
                    $preview->getLastPrepareQuery(), $preview->getLastBindParams(), $preview->getLastQueryOptions()]));
                $groups[$key][] = ['position' => $position, 'method' => $method, 'plan' => $plan, 'lookup' => $lookup];
            }
            $position++;
            unset($parent);
        }
        return $groups;
    }

    /** 每组只执行批量查询，再按原父记录位置回填独立的关联结果。 */
    private function attachResults(array $parents, array $groups, bool $asArray, callable $execute): array
    {
        $db = FastDb::getInstance();
        foreach ($groups as $entries) {
            $matches = $this->fetch($entries, $execute);
            foreach ($entries as $entry) {
                $plan = $entry['plan'];
                $matched = $matches[serialize($plan->selfValue)] ?? [];
                $db->selectConnection($plan->connectionName);
                $data = $this->materialize($plan, $matched, $asArray);
                if ($asArray) {
                    $parents[$entry['position']][$entry['method']] = $data;
                } else {
                    $parents[$entry['position']]->__setRelationData($plan, $entry['lookup'], $plan->connectionName, $data);
                }
            }
        }
        return $parents;
    }

    /** 由数据库匹配父键，避免 PHP 字符串分桶改变数据库的类型、collation 或 NULL 比较。 */
    private function fetch(array $entries, callable $execute): array
    {
        $db = FastDb::getInstance();
        $plan = $entries[0]['plan'];
        $values = [];
        foreach ($entries as $entry) {
            $value = $entry['plan']->selfValue;
            $values[serialize($value)] = $value;
        }
        $matches = [];
        foreach (array_chunk($values, self::BATCH_SIZE, true) as $batch) {
            // 前一批的查询回调可能已选中其他连接。
            $db->selectConnection($plan->connectionName);
            $token = '__fd_rel_' . bin2hex(random_bytes(6));
            $indexName = $token . '_index';
            $alias = $token . '_parents';
            $sql = [];
            $parameters = [];
            $identities = array_keys($batch);
            $conditions = [];
            $foreign = TableReference::column($plan->relate->targetProperty);
            $parentIndex = TableReference::column($alias . '.' . $indexName);
            foreach (array_values($batch) as $index => $value) {
                $sql[] = 'SELECT ' . $index . ' AS ' . TableReference::identifier($indexName);
                $comparison = $value === null ? $foreign . ' = NULL' : $foreign . ' = ?';
                $conditions[] = '(' . $parentIndex . ' = ' . $index . ' AND ' . $comparison . ')';
                if ($value !== null) { $parameters[] = $value; }
            }
            $keys = QueryBuilder::subQuery($alias)->raw(implode(' UNION ALL ', $sql));
            $builder = clone $plan->builder;
            $columns = $plan->columns ?: $builder->getField();
            $columns[] = QueryBuilder::rawExpression($parentIndex . ' AS ' . TableReference::identifier($indexName));
            // 派生表只承载数字位置；键仍作为常量参数比较，保留类型及 collation 规则。
            $builder->join($keys, QueryBuilder::rawExpression('(' . implode(' OR ', $conditions) . ')', $parameters), 'INNER');
            $builder->groupExistingWhere()->whereGroup(static function (QueryBuilder $query) use ($batch, $foreign): void {
                foreach ($batch as $value) { $query->where($foreign, $value, '=', 'OR'); }
            })
                ->get($plan->tableName, null, $columns);
            $rows = $execute($builder)->getResult();
            foreach ($rows as $row) {
                $identity = $identities[$row[$indexName]];
                unset($row[$indexName]);
                $matches[$identity][] = $row;
            }
        }
        return $matches;
    }

    private function materialize(RelationPlan $plan, array $rows, bool $parentAsArray): mixed
    {
        $asArray = $parentAsArray || $plan->returnAsArray;
        if ($plan->relate->type === RelationType::One) {
            if (count($rows) > 1) {
                throw new RuntimeError('more than one record hit is no allow in relateOne method');
            }
            if ($rows === []) { return null; }
            return $asArray ? $rows[0] : new ($plan->relate->targetEntity)($rows[0]);
        }
        if (!$asArray) {
            foreach ($rows as $index => $row) { $rows[$index] = new ($plan->relate->targetEntity)($row); }
        }
        // 数组父结果直接附加行列表；实体的 returnAsArray 关系仍返回 ListResult。
        return $parentAsArray ? $rows : new ListResult($rows);
    }
}
