<?php

namespace EasySwoole\FastDb;

use EasySwoole\Mysqli\QueryBuilder;

class FastDb
{
    protected QueryBuilder|null $queryBuilder = null;

    function __construct()
    {
        $this->queryBuilder = new QueryBuilder();
    }

    function getOne(string $targetEntity):?Entity
    {
        return null;
    }

    function all(string $targetEntity)
    {

    }

    function save(Entity $entity)
    {

    }

    function update(Entity $entity)
    {

    }

    function delete(Entity $entity)
    {

    }

    function chunk(callable $func,string $targetEntity,$chunkSize = 10)
    {

    }
}