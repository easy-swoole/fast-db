<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;

/** 负责按需关联的查询组装、缓存和结果转换，不持有父实体或执行回调。 */
final class RelationLoader
{
    private array $cache = [];
    private array $preloaded = [];

    public function clear(): void { $this->cache = $this->preloaded = []; }

    /** 显式预取独立于按需缓存，null 同样是有效结果。 */
    public function prime(Relate $relate, QueryBuilder $lookup, string $kind, bool $returnAsArray, string $connectionName, mixed $data): void
    {
        $this->preloaded[$this->fingerprint($relate, $lookup, $kind, $returnAsArray, $connectionName)] = $data;
    }

    /** 两种关联共用构建、缓存查找和写回流程，结果组装分别处理。 */
    public function load(AbstractEntity $entity, Relate $relate, ?string $tableName, string $kind,
        callable $execute, callable $beforeOneHydration, callable $readTotal): mixed
    {
        [$target, $query, $returnAsArray, $key] = $this->prepare($entity, $relate, $tableName, $kind);
        if ($key !== null && array_key_exists($key, $this->preloaded)) {
            return $this->preloaded[$key];
        }
        if ($relate->cacheResult && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $result = $execute($query);
        $rows = $result->getResult();
        if ($kind === 'one') {
            if (empty($rows)) { $data = null; }
            elseif (count($rows) > 1) { throw new RuntimeError('more than one record hit is no allow in relateOne method'); }
            elseif ($returnAsArray) { $data = $rows[0]; }
            else {
                // 单条实体赋值前结束父查询配置，字段转换可重入父模型；最终清理由实体入口负责。
                $beforeOneHydration();
                $target->setData($rows[0], true);
                $data = $target;
            }
        } else {
            $items = [];
            foreach ($rows as $row) {
                $items[] = $returnAsArray ? $row : new $relate->targetEntity($row);
            }
            $total = $readTotal($result);
            $data = new ListResult($items, $total);
        }
        if ($relate->cacheResult) { $this->cache[$key] = $data; }
        return $data;
    }

    /** 目标初始化、表名求值和缓存键计算的先后顺序与普通关联查询一致。 */
    private function prepare(AbstractEntity $entity, Relate $relate, ?string $tableName, string $kind): array
    {
        $target = new $relate->targetEntity();
        $selection = $entity->queryLimit();
        $query = $selection->__getQueryBuilder();
        $fields = $selection->getFields();
        $returnAsArray = $fields['returnAsArray'] ?? false;
        RelationPlan::buildLookup($query, $tableName ?: $target->tableName(), $relate, $kind,
            FieldCodec::compare($entity->{$relate->selfProperty} ?? null), $fields['fields'] ?? null);
        $key = $relate->cacheResult || $this->preloaded !== []
            ? $this->fingerprint($relate, $query, $kind, $returnAsArray, FastDb::getInstance()->selectConnection()) : null;
        return [$target, $query, $returnAsArray, $key];
    }

    private function fingerprint(Relate $relate, QueryBuilder $query, string $kind, bool $returnAsArray, string $connectionName): string
    {
        return hash('sha256', serialize([
            $connectionName, $kind, $relate->targetEntity, $returnAsArray,
            $query->getLastPrepareQuery(), $query->getLastBindParams(), $query->getLastQueryOptions(),
        ]));
    }
}
