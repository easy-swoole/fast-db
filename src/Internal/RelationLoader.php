<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Beans\ListResult;
use EasySwoole\FastDb\Exception\RuntimeError;
use EasySwoole\FastDb\FastDb;
use EasySwoole\Mysqli\QueryBuilder;

/** 仅保存关联结果缓存，不持有所属实体或执行回调，避免形成额外强引用环。 */
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

    public function one(AbstractEntity $entity, Relate $relate, ?string $tableName, callable $execute, callable $reset, callable $readTotal): null|array|AbstractEntity
    {
        return $this->load($entity, $relate, $tableName, 'one', $execute, $reset, $readTotal);
    }

    public function many(AbstractEntity $entity, Relate $relate, ?string $tableName, callable $execute, callable $reset, callable $readTotal): ListResult
    {
        return $this->load($entity, $relate, $tableName, 'many', $execute, $reset, $readTotal);
    }

    /** 两种关联共用构建、缓存查找和写回流程，结果组装分别处理。 */
    private function load(AbstractEntity $entity, Relate $relate, ?string $tableName, string $kind,
        callable $execute, callable $reset, callable $readTotal): mixed
    {
        [$target, $query, $returnAsArray, $key] = $this->prepare($entity, $relate, $tableName, $kind);
        if ($key !== null && array_key_exists($key, $this->preloaded)) {
            $reset();
            return $this->preloaded[$key];
        }
        if ($relate->cacheResult && $key !== null && array_key_exists($key, $this->cache)) {
            $reset();
            return $this->cache[$key];
        }

        $result = $execute($query);
        $rows = $result->getResult();
        if ($kind === 'one') {
            // 单条关联沿用先清理查询、再执行字段赋值转换的顺序。
            $reset();
            if (empty($rows)) { $data = null; }
            elseif (count($rows) > 1) { throw new RuntimeError('more than one record hit is no allow in relateOne method'); }
            elseif ($returnAsArray) { $data = $rows[0]; }
            else {
                $target->setData($rows[0], true);
                $data = $target;
            }
        } else {
            $items = [];
            foreach ($rows as $row) {
                $items[] = $returnAsArray ? $row : new $relate->targetEntity($row);
            }
            $total = in_array('SQL_CALC_FOUND_ROWS', $result->getQueryBuilder()?->getLastQueryOptions() ?? [], true) ? $readTotal($result) : null;
            $reset();
            $data = new ListResult($items, $total);
        }
        if ($relate->cacheResult && $key !== null) { $this->cache[$key] = $data; }
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
            $entity->{$relate->selfProperty} ?? null, $fields['fields'] ?? null);
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
