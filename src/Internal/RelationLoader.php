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
        callable $execute, callable $beforeHydration, callable $hydrate): mixed
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
            if ($db !== null) {
                // 子模型初始化或赋值可能重入父模型，回填前结束本次关联的查询配置。
                $beforeHydration();
            }
            $data = self::materialize($relate, $rows, $returnAsArray, $result->getConnectionName(), $hydrate, $target);
        } finally {
            if ($db !== null) { $db->selectConnection($connectionToRestore); }
        }
        if ($relate->type === RelationType::Many) { $data = new ListResult($data); }
        if ($relate->cacheResult) { $this->cache[$key] = $data; }
        return $data;
    }

    /** 按需加载与预加载共用基数检查；调用方负责恢复选库并组装列表返回值。 */
    public static function materialize(Relate $relate, array $rows, bool $returnAsArray, string $connectionName,
        callable $hydrate, ?AbstractEntity $target): AbstractEntity|array|null
    {
        $single = $relate->type === RelationType::One;
        if ($single && count($rows) > 1) {
            throw new RuntimeError('more than one record hit is no allow in relateOne method');
        }

        $db = $returnAsArray ? null : FastDb::getInstance();
        $result = [];
        foreach ($rows as $row) {
            if ($db !== null) {
                // 上一条记录的构造器可能切库，每条都从查询连接开始回填。
                $db->selectConnection($connectionName);
                $row = $hydrate($relate->targetEntity, $row, $single ? $target : null);
            }
            $result[] = $row;
        }
        return $single ? ($result[0] ?? null) : $result;
    }

    private function fingerprint(Relate $relate, QueryBuilder $query, bool $returnAsArray, string $connectionName): string
    {
        return hash('sha256', serialize([
            $connectionName, $relate->type, $relate->targetEntity, $returnAsArray,
            $query->getLastPrepareQuery(), $query->getLastBindParams(), $query->getLastQueryOptions(),
        ]));
    }
}
