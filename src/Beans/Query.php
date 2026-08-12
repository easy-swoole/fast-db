<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\Mysqli\QueryBuilder;

class Query
{
    private QueryBuilder $queryBuilder;

    private ?array $fields = null;

    private ?array $hideFields = null;

    private bool $persistFieldLimit = false;

    public function __construct(
        private readonly AbstractEntity $entity
    ){
        $this->queryBuilder = new QueryBuilder();
    }

    function limit(int $num,bool $withTotalCount = false):Query
    {
        $this->page(null,$withTotalCount,$num);
        return $this;
    }

    function persistFieldLimit(bool $persistFieldLimit = true):Query
    {
        $this->persistFieldLimit = $persistFieldLimit;
        return $this;
    }

    function isPersistFieldLimit():bool
    {
        return $this->persistFieldLimit;
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
        $this->fields = [
            'fields'=>$fields,
            'returnAsArray'=>$returnAsArray
        ];
        return $this;
    }

    function returnAsArray():Query
    {
        if(!is_array($this->fields)){
            $this->fields = [
                'returnAsArray'=>true,
                'fields'=>null
            ];
        }else{
            $this->fields['returnAsArray'] = true;
        }
        return $this;
    }

    function hideFields(array|string $hideFields):Query
    {
        if(is_string($hideFields)){
            $hideFields = [$hideFields];
        }
        $this->hideFields = $hideFields;
        return $this;
    }

    function getHideFields():?array
    {
        return $this->hideFields;
    }

    function getFields():?array
    {
        return $this->fields;
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

    function __clone()
    {
        //chunk 的时候，复用query builder
        $this->queryBuilder = clone $this->queryBuilder;
    }
}