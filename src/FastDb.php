<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\Mysqli\QueryBuilder;

class FastDb
{
    use Singleton;

    protected QueryBuilder|null $queryBuilder = null;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];

    function __construct()
    {
        $this->queryBuilder = new QueryBuilder();
    }

    function addDb(string $name,Config $config):FastDb
    {
        $this->configs[$name] = $config;

        return $this;
    }

    function selectDb(string $name):FastDb
    {
        return $this;
    }

    function reset():FastDb
    {
        return $this;
    }

    function invokeMode(bool $is):FastDb
    {
        return $this;
    }

    function beginTransaction():FastDb
    {
        return $this;
    }

    function commit()
    {

    }

    function rollback()
    {

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