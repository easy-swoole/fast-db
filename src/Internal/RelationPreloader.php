<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Utility\ReflectionCache;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\Mysqli\QueryBuilder\TableReference;

/** 仅在显式预加载时创建；计划、分桶及临时父模型只在当前批次内存活。 */
final class RelationPreloader
{
    private const BATCH_SIZE = 250;

    public function load(AbstractEntity $source, array $parents, array $rows, array $relations,
        bool $asArray, string $connectionName, array $hiddenFields, callable $execute, callable $hydrate): array
    {
        if ($parents === []) { return $parents; }
        $db = FastDb::getInstance();
        // 各关联可选用不同的库，结束后恢复调用者进入预加载前的选择。
        $connectionToRestore = $db->selectConnection();
        try {
            return $this->attachResults(
                $parents,
                $this->collectPlans($source, $parents, $rows, $relations, $asArray, $connectionName, $hiddenFields),
                $asArray,
                $execute,
                $hydrate
            );
        } finally {
            $db->selectConnection($connectionToRestore);
        }
    }

    /** 逐父解析显式配置，按实际 SQL 和参数合并可共享的查询。 */
    private function collectPlans(AbstractEntity $source, array $parentResults, array $parentRows, array $relationMethods,
        bool $returnAsArray, string $parentQueryConnection, array $hiddenFields): array
    {
        $db = FastDb::getInstance();
        $groups = [];
        $parentPosition = 0;
        $parentClass = $source::class;
        $metadata = ReflectionCache::getInstance();
        $parentFields = [];
        foreach ($relationMethods as $method) {
            $parentFields[] = $metadata->parseRelation($parentClass, $method)->selfProperty;
        }

        foreach ($parentRows as $parentRow) {
            // 配置方法可能读取父键，先检查原始投影，再生成关联计划。
            foreach ($parentFields as $parentField) {
                if (!array_key_exists($parentField, $parentRow)) {
                    throw new RuntimeError('Preloading requires the parent relation field to be selected under its original name: ' . $parentField);
                }
                if (!$returnAsArray && in_array($parentField, $hiddenFields, true)) {
                    throw new RuntimeError('An entity relation field cannot be hidden while preloading: ' . $parentField);
                }
            }
            if ($returnAsArray) {
                // 临时父模型的初始化和字段转换沿用父查询实际连接。
                $db->selectConnection($parentQueryConnection);
                $parent = new $parentClass($parentRow);
            } else {
                $parent = $parentResults[$parentPosition];
            }
            foreach ($relationMethods as $relationMethod) {
                // 上一个关联的配置可能切库，每个关联都从父查询的连接开始配置。
                $db->selectConnection($parentQueryConnection);
                if ($returnAsArray && array_key_exists($relationMethod, $parentResults[$parentPosition])) {
                    throw new RuntimeError('Relation name conflicts with a selected field: ' . $relationMethod);
                }
                $relationPlan = $parent->__relationPlan($relationMethod);
                $parentKeyValue = $relationPlan->selfValue;
                if ($parentKeyValue !== null && !is_int($parentKeyValue) && !is_string($parentKeyValue)) {
                    throw new RuntimeError('Parent relation fields must contain an integer, string or null');
                }
                $db->selectConnection($relationPlan->connectionName);
                $tableName = $relationPlan->tableName;
                $builder = $relationPlan->builder;
                RelationPlan::checkPagination($builder, $relationPlan->relate->type, preload: true);
                $columns = $relationPlan->columns;
                // 先生成不带具体父键的 SQL，配置和参数相同的父记录可共用一条批量查询。
                $previewQuery = (clone $builder)->get($tableName, null, $columns);
                $queryOptions = $previewQuery->getLastQueryOptions();
                $cacheLookup = null;
                if (!$returnAsArray) {
                    // 实体 getter 按单个父键匹配缓存，因此要保留该父对象自己的查询。
                    $cacheLookup = RelationPlan::buildLookup(
                        clone $builder, $tableName, $relationPlan->relate, $parentKeyValue, $columns
                    );
                }
                $groupKey = hash('sha256', serialize([
                    $relationPlan->connectionName, $relationPlan->relate->type, $relationPlan->relate->targetEntity,
                    $relationPlan->relate->targetProperty, $tableName, $relationPlan->returnAsArray,
                    $previewQuery->getLastPrepareQuery(), $previewQuery->getLastBindParams(), $queryOptions,
                ]));
                // 保留键值的类型，避免 PHP 数组键把字符串 '1' 和整数 1 当作同一个键。
                $serializedParentKey = serialize($parentKeyValue);
                $groups[$groupKey]['batchPlan'] ??= $relationPlan;
                $groups[$groupKey]['parentKeyValues'][$serializedParentKey] = $parentKeyValue;
                $groups[$groupKey]['parentEntries'][] = [
                    'parentPosition' => $parentPosition,
                    'relationMethod' => $relationMethod,
                    'serializedParentKey' => $serializedParentKey,
                    'cacheLookup' => $cacheLookup,
                    // 同一组共用 SQL，每个父对象的目标实例各自回填。
                    'target' => $returnAsArray ? null : $relationPlan->target,
                ];
            }
            $parentPosition++;
            unset($parent);
        }
        return $groups;
    }

    /** 每组只执行批量查询，再按原父记录位置回填独立的关联结果。 */
    private function attachResults(array $parentResults, array $groups, bool $returnAsArray, callable $execute,
        callable $hydrate): array
    {
        // 只遍历分组键，避免遍历期间 PHP 保留整份分组数据，便于回填后释放每组的缓存查询和结果行。
        foreach (array_keys($groups) as $groupKey) {
            $group = $groups[$groupKey];
            unset($groups[$groupKey]);
            $batchPlan = $group['batchPlan'];
            $rowsByParentKey = $this->fetch($batchPlan, $group['parentKeyValues'], $execute);
            foreach ($group['parentEntries'] as $parentEntry) {
                $parentPosition = $parentEntry['parentPosition'];
                $matchedRows = $rowsByParentKey[$parentEntry['serializedParentKey']] ?? [];
                $relationData = RelationLoader::materialize(
                    $batchPlan->relate, $matchedRows, $returnAsArray || $batchPlan->returnAsArray,
                    $batchPlan->connectionName, $hydrate, $parentEntry['target']
                );
                if (!$returnAsArray && $batchPlan->relate->type === RelationType::Many) {
                    $relationData = new ListResult($relationData);
                }
                if ($returnAsArray) {
                    $parentResults[$parentPosition][$parentEntry['relationMethod']] = $relationData;
                } else {
                    $parentResults[$parentPosition]->__setRelationData(
                        $batchPlan, $parentEntry['cacheLookup'], $batchPlan->connectionName, $relationData
                    );
                }
            }
            unset($group, $parentEntry, $rowsByParentKey, $matchedRows);
        }
        return $parentResults;
    }

    /** 由数据库匹配父键，避免 PHP 字符串分桶改变数据库的类型、collation 或 NULL 比较。 */
    private function fetch(RelationPlan $plan, array $values, callable $execute): array
    {
        $db = FastDb::getInstance();
        $matches = [];
        $foreign = RelationPlan::foreignKey($plan->builder, $plan->tableName, $plan->relate->targetProperty);
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
            $parentIndex = TableReference::column($alias . '.' . $indexName);
            foreach (array_values($batch) as $index => $value) {
                $sql[] = 'SELECT ' . $index . ' AS ' . TableReference::identifier($indexName);
                $comparison = $value === null ? $foreign . ' IS NULL' : $foreign . ' = ?';
                $conditions[] = '(' . $parentIndex . ' = ' . $index . ' AND ' . $comparison . ')';
                if ($value !== null) { $parameters[] = $value; }
            }
            $keys = QueryBuilder::subQuery($alias)->raw(implode(' UNION ALL ', $sql));
            $builder = clone $plan->builder;
            // 保留调用方投影或 mysqli 的默认通配符，只追加内部匹配列。
            $columns = $plan->columns ?? $builder->getField() ?? ['*'];
            $columns[] = QueryBuilder::rawExpression($parentIndex . ' AS ' . TableReference::identifier($indexName));
            // 派生表只承载数字位置；键仍作为常量参数比较，保留类型及 collation 规则。
            $builder->join($keys, QueryBuilder::rawExpression('(' . implode(' OR ', $conditions) . ')', $parameters), 'INNER');
            $builder->groupExistingWhere()->whereGroup(static function (QueryBuilder $query) use ($batch, $foreign): void {
                foreach ($batch as $value) {
                    if ($value === null) {
                        $query->whereNull($foreign, 'OR');
                    } else {
                        $query->where($foreign, $value, '=', 'OR');
                    }
                }
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
}
