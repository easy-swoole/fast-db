<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\Mysqli\QueryBuilder;

class Query
{
    private QueryBuilder $queryBuilder;
    public function __construct(
        private AbstractEntity $entity
    ){
        $this->queryBuilder = new QueryBuilder();
    }

    function limit(int $num,bool $withTotalCount = false):Query
    {
        $this->page(null,$withTotalCount,$num);
        return $this;
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

    function returnEntity():AbstractEntity
    {
        return $this->entity;
    }

    function __getQueryBuilder():QueryBuilder
    {
        return $this->queryBuilder;
    }

}