<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\RelationType;
use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Beans\Query;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;

/** @internal 负责按需关联的查询组装、缓存和结果转换，不持有父实体或执行回调。 */
final class RelationLoader
{
    private array $cache = [];
    private array $preloaded = [];

    public function clear(): void { $this->cache = $this->preloaded = []; }

    /** 显式预取独立于按需缓存，null 同样是有效结果。 */
    public function prime(Relate $relate, QueryBuilder $lookup, bool $returnAsArray, string $connectionName, mixed $data): void
    {
        $this->preloaded[$this->fingerprint($relate, $lookup, $returnAsArray, $connectionName)] = $data;
    }

    /** 两种关联共用构建、缓存查找和写回流程，结果组装分别处理。 */
    public function load(AbstractEntity $entity, Query $selection, Relate $relate, ?string $tableName,
        callable $execute, callable $beforeOneHydration, callable $hydrate): mixed
    {
        $target = new $relate->targetEntity();
        $query = $selection->__getQueryBuilder();
        $fields = $selection->getFields();
        $returnAsArray = $fields['returnAsArray'] ?? false;
        RelationPlan::checkPagination($query, $relate->type);
        RelationPlan::buildLookup($query, $tableName ?? $target->tableName(), $relate,
            FieldCodec::compare($entity->{$relate->selfProperty}), $fields['fields'] ?? null);
        $key = $relate->cacheResult || $this->preloaded !== []
            ? $this->fingerprint($relate, $query, $returnAsArray, FastDb::getInstance()->selectConnection()) : null;
        if ($key !== null && array_key_exists($key, $this->preloaded)) {
            return $this->preloaded[$key];
        }
        if ($relate->cacheResult && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $result = $execute($query);
        $rows = $result->getResult();
        $db = !$returnAsArray && $rows !== [] ? FastDb::getInstance() : null;
        $connectionToRestore = $db?->selectConnection();
        try {
            if ($relate->type === RelationType::One) {
                if (empty($rows)) { $data = null; }
                elseif (count($rows) > 1) { throw new RuntimeError('more than one record hit is no allow in relateOne method'); }
                elseif ($returnAsArray) { $data = $rows[0]; }
                else {
                    // 单条实体赋值前结束父查询配置，子类赋值或字段转换时可重入父模型；最终清理由实体入口负责。
                    $beforeOneHydration();
                    $db->selectConnection($result->getConnectionName());
                    $data = $hydrate($relate->targetEntity, $rows[0], $target);
                }
            } else {
                $items = [];
                foreach ($rows as $row) {
                    if ($returnAsArray) {
                        $items[] = $row;
                    } else {
                        // 上一个模型的初始化可能切库，每次填充都使用本次查询的连接。
                        $db->selectConnection($result->getConnectionName());
                        $items[] = $hydrate($relate->targetEntity, $row);
                    }
                }
                $data = new ListResult($items);
            }
        } finally {
            if ($db !== null) { $db->selectConnection($connectionToRestore); }
        }
        if ($relate->cacheResult) { $this->cache[$key] = $data; }
        return $data;
    }

    private function fingerprint(Relate $relate, QueryBuilder $query, bool $returnAsArray, string $connectionName): string
    {
        return hash('sha256', serialize([
            $connectionName, $relate->type, $relate->targetEntity, $returnAsArray,
            $query->getLastPrepareQuery(), $query->getLastBindParams(), $query->getLastQueryOptions(),
        ]));
    }
}
