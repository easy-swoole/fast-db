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
    public function clear(): void { $this->cache = []; }

    private function cacheKey(Relate $relate, QueryBuilder $query, string $kind, bool $returnAsArray): ?string
    {
        if (!$relate->cacheResult) {
            return null;
        }
        return hash('sha256', serialize([
            FastDb::getInstance()->selectConnection(),
            $kind,
            $relate->targetEntity,
            $returnAsArray,
            $query->getLastPrepareQuery(),
            $query->getLastBindParams(),
            $query->getLastQueryOptions(),
        ]));
    }

    /** 统一构造关联查询，使两种关联使用相同的字段、连接和缓存键规则。 */
    private function prepare(AbstractEntity $entity, Relate $relate, ?string $tableName, string $kind): array
    {
        $target = new $relate->targetEntity();
        $query = $entity->queryLimit()->__getQueryBuilder();
        $selection = $entity->queryLimit()->getFields();
        $fields = $selection['fields'] ?? null;
        $returnAsArray = $selection['returnAsArray'] ?? false;
        $value = $entity->{$relate->selfProperty} ?? null;
        $query->where($relate->targetProperty, $value)->get($tableName ?: $target->tableName(), $kind === 'one' ? 2 : null, $fields);
        return [$target, $query, $returnAsArray, $this->cacheKey($relate, $query, $kind, $returnAsArray)];
    }

    public function one(AbstractEntity $entity, Relate $relate, ?string $tableName, callable $execute, callable $reset, callable $readTotal): null|array|AbstractEntity
    {
        [$temp, $query, $returnAsArray, $cacheKey] = $this->prepare($entity, $relate, $tableName, 'one');
        // array_key_exists 能识别已缓存的 null；命中后仍清理本次查询配置。
        if ($cacheKey !== null && array_key_exists($cacheKey, $this->cache)) {
            $reset();
            return $this->cache[$cacheKey];
        }
        $ret = $execute($query)->getResult();
        $reset();
        if(empty($ret)){
            if ($cacheKey !== null) {
                $this->cache[$cacheKey] = null;
            }
            return null;
        }
        if(count($ret) > 1){
            $msg = "more than one record hit is no allow in relateOne method";
            throw new RuntimeError($msg);
        }
        if($returnAsArray){
            if ($cacheKey !== null) {
                $this->cache[$cacheKey] = $ret[0];
            }
            return $ret[0];
        }
        // 关联加载的数据也应作为比较基准，避免未修改的字段被整行更新。
        $temp->setData($ret[0],true);
        if ($cacheKey !== null) {
            $this->cache[$cacheKey] = $temp;
        }
        return $temp;

    }

    public function many(AbstractEntity $entity, Relate $relate, ?string $tableName, callable $execute, callable $reset, callable $readTotal): ListResult
    {
        [$temp, $query, $returnAsArray, $cacheKey] = $this->prepare($entity, $relate, $tableName, 'many');
        if ($cacheKey !== null && array_key_exists($cacheKey, $this->cache)) {
            $reset();
            return $this->cache[$cacheKey];
        }
        $queryResult = $execute($query);
        $ret = $queryResult->getResult();

        $final = [];
        foreach ($ret as $item){
            if($returnAsArray){
                $final[] = $item;
            }else{
                $final[] = new $relate->targetEntity($item);
            }
        }
        $total = null;
        if(in_array('SQL_CALC_FOUND_ROWS',$query->getLastQueryOptions())){
            $total = $readTotal($queryResult);
        }
        $reset();
        $result = new ListResult($final,$total);
        if ($cacheKey !== null) {
            $this->cache[$cacheKey] = $result;
        }
        return $result;
    }
}
