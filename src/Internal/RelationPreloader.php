<?php

namespace EasySwoole\FastDb\Internal;

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
            if (!is_array($row)) { throw new RuntimeError('Preloaded parent rows must be arrays'); }
            // 数组输出也需要实例配置读取父字段，临时模型不进入返回结果。
            $parent = $asArray ? new $class($row) : $parents[$position];
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
                // 数组模式释放临时父模型，不通过 Query 所有者延长其生命周期。
                if ($asArray) {
                    $plan = new RelationPlan($plan->kind, $plan->relate, $plan->tableName,
                        $plan->query->forEntity($source), $plan->connectionName, $value);
                }
                $db->selectConnection($plan->connectionName);
                $table = $plan->tableName;
                $builder = $plan->query->__getQueryBuilder();
                // 与驱动游标页共用普通 SELECT 检查，不执行生成的游标页。
                $plan->query->forPageAfterId($plan->relate->targetProperty, null, 1);
                $fields = $plan->query->getFields();
                $columns = $fields['fields'] ?? null;
                $preview = (clone $builder)->get($table, null, $columns);
                if (preg_match('/\sLIMIT\s+-?\d+(?:\s*,\s*-?\d+)?\s*\z/i', $preview->getLastPrepareQuery())) {
                    throw new \InvalidArgumentException('Preloading does not support per-parent limits or pagination');
                }
                $lookup = RelationPlan::buildLookup(clone $builder, $table, $plan->relate,
                    $plan->kind, $value, $columns);
                $mode = (bool) ($fields['returnAsArray'] ?? false);
                $key = hash('sha256', serialize([$plan->connectionName, $plan->kind,
                    $plan->relate->targetEntity, $plan->relate->targetProperty, $table, $mode,
                    $preview->getLastPrepareQuery(), $preview->getLastBindParams(), $preview->getLastQueryOptions()]));
                $groups[$key][] = ['position' => $position, 'method' => $method, 'plan' => $plan,
                    'value' => $value, 'lookup' => $lookup, 'arrayMode' => $mode];
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
            $db->selectConnection($entries[0]['plan']->connectionName);
            $matches = $this->fetch($entries, $execute);
            foreach ($entries as $entry) {
                $matched = $matches[serialize($entry['value'])] ?? [];
                $plan = $entry['plan'];
                if ($plan->kind === 'one' && count($matched) > 1) {
                    throw new RuntimeError('more than one record hit is no allow in relateOne method');
                }
                $arrayMode = $asArray || $entry['arrayMode'];
                $db->selectConnection($plan->connectionName);
                $data = $this->materialize($plan, $matched, $arrayMode, $asArray);
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
        $first = $entries[0];
        $plan = $first['plan'];
        $values = [];
        foreach ($entries as $entry) { $values[serialize($entry['value'])] = $entry['value']; }
        $matches = [];
        foreach (array_chunk($values, self::BATCH_SIZE, true) as $batch) {
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
            $builder = clone $plan->query->__getQueryBuilder();
            $columns = $plan->query->getFields()['fields'] ?? null;
            if (empty($columns)) { $columns = $builder->getField(); }
            $columns = is_array($columns) ? $columns : [$columns];
            $columns[] = QueryBuilder::rawExpression($parentIndex . ' AS ' . TableReference::identifier($indexName));
            // 派生表只承载数字位置；键仍作为常量参数比较，保留类型及 collation 规则。
            $builder->join($keys, QueryBuilder::rawExpression('(' . implode(' OR ', $conditions) . ')', $parameters), 'INNER');
            $builder->groupExistingWhere()->whereGroup(static function (QueryBuilder $query) use ($batch, $foreign): void {
                foreach ($batch as $value) { $query->where($foreign, $value, '=', 'OR'); }
            })
                ->get($plan->tableName, null, $columns);
            FastDb::getInstance()->selectConnection($plan->connectionName);
            $rows = $execute($builder)->getResult();
            if (!is_array($rows)) { throw new RuntimeError('Preload queries must return result rows'); }
            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists($indexName, $row)
                    || (!is_int($row[$indexName]) && !(is_string($row[$indexName]) && preg_match('/\A[0-9]+\z/D', $row[$indexName])))
                    || !array_key_exists((int) $row[$indexName], $identities)) {
                    throw new RuntimeError('Preload result is missing its parent matching column');
                }
                $identity = $identities[(int) $row[$indexName]];
                unset($row[$indexName]);
                $matches[$identity][] = $row;
            }
        }
        return $matches;
    }

    private function materialize(RelationPlan $plan, array $rows, bool $asArray, bool $plainList): mixed
    {
        if ($plan->kind === 'one') {
            if ($rows === []) { return null; }
            return $asArray ? $rows[0] : new ($plan->relate->targetEntity)($rows[0]);
        }
        if (!$asArray) {
            foreach ($rows as $index => $row) { $rows[$index] = new ($plan->relate->targetEntity)($row); }
        }
        // 数组父结果直接附加行列表；实体的 returnAsArray 关系仍返回 ListResult。
        return $plainList ? $rows : new ListResult($rows);
    }
}
