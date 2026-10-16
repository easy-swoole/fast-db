<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Internal\FieldSelection;
use EasySwoole\Mysqli\QueryBuilder;

class Query
{
    private QueryBuilder $queryBuilder;

    // SQL 条件由 QueryBuilder 管理，输出筛选规则单独保存。
    private FieldSelection $selection;

    public function __construct(
        private readonly AbstractEntity $entity
    ){
        $this->queryBuilder = new QueryBuilder();
        $this->selection = new FieldSelection();
    }

    function limit(int $num,bool $withTotalCount = false):Query
    {
        $this->page(null,$withTotalCount,$num);
        return $this;
    }

    function persistFieldLimit(bool $persistFieldLimit = true):Query
    {
        $this->selection->persistFieldLimit = $persistFieldLimit;
        return $this;
    }

    function isPersistFieldLimit():bool
    {
        return $this->selection->persistFieldLimit;
    }

    function page(?int $page,bool $withTotalCount = false,int $pageSize = 10):Query
    {
        $page = new Page($page,$withTotalCount,$pageSize);
        $this->queryBuilder->limit(...$page->toLimitArray());
        if($withTotalCount){
            $this->queryBuilder->withTotalCount();
        }
        return $this;
    }

    function fields(?array $fields = null,bool $returnAsArray = false):Query
    {
        $this->selection->fields = [
            'fields'=>$fields,
            'returnAsArray'=>$returnAsArray
        ];
        return $this;
    }

    function returnAsArray():Query
    {
        if(!is_array($this->selection->fields)){
            $this->selection->fields = [
                'returnAsArray'=>true,
                'fields'=>null
            ];
        }else{
            $this->selection->fields['returnAsArray'] = true;
        }
        return $this;
    }

    function hideFields(array|string $hideFields):Query
    {
        if(is_string($hideFields)){
            $hideFields = [$hideFields];
        }
        $this->selection->hideFields = $hideFields;
        return $this;
    }

    function getHideFields():?array
    {
        return $this->selection->hideFields;
    }

    function getFields():?array
    {
        return $this->selection->fields;
    }

    function groupBy(string $groupByField):Query
    {
        $this->queryBuilder->groupBy($groupByField);
        return $this;
    }

    function orderBy($orderByField, $orderbyDirection = "DESC", $customFieldsOrRegExp = null):Query
    {
        $this->queryBuilder->orderBy($orderByField, $orderbyDirection, $customFieldsOrRegExp);
        return $this;
    }

    function where(string $col, mixed $whereValue, $operator = '=', $cond = 'AND'):Query
    {
        $this->queryBuilder->where($col,$whereValue,$operator,$cond);
        return $this;
    }

    function orWhere(string $col, mixed $whereValue, $operator = '='):Query
    {
        $this->queryBuilder->orWhere($col,$whereValue,$operator,"OR");
        return $this;
    }

    function join($joinTable, $joinCondition, $joinType = ''):Query
    {
        $this->queryBuilder->join($joinTable,$joinCondition,$joinType);
        return $this;
    }

    function func(callable $func):Query
    {
        call_user_func($func,$this->queryBuilder);
        return $this;
    }

    function selectForUpdate():Query
    {
        $this->queryBuilder->selectForUpdate();
        return $this;
    }

    function returnEntity():AbstractEntity
    {
        return $this->entity;
    }

    function __getQueryBuilder():QueryBuilder
    {
        return $this->queryBuilder;
    }

    /** 清理 SQL 条件但保留输出配置，供序列化及插入后的兼容清理使用。 */
    public function withoutSqlConditions(): Query
    {
        $copy = new Query($this->entity);
        $copy->selection = clone $this->selection;
        return $copy;
    }

    /** 克隆实体时重新绑定所有者，同时复制 SQL 条件和输出配置。 */
    public function forEntity(AbstractEntity $entity): Query
    {
        $copy = new Query($entity);
        $copy->queryBuilder = clone $this->queryBuilder;
        $copy->selection = clone $this->selection;
        return $copy;
    }

    function __clone()
    {
        //chunk 的时候，复用query builder
        $this->queryBuilder = clone $this->queryBuilder;
        $this->selection = clone $this->selection;
    }
}