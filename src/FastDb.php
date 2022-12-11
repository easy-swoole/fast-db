<?php

namespace EasySwoole\FastDb;

use EasySwoole\Component\Singleton;
use EasySwoole\Mysqli\QueryBuilder;

class FastDb
{
    use Singleton;

    protected array $configs = [];
    protected array $pools = [];
    protected array $currentConnection = [];

    function addDb(Config $config):FastDb
    {
        $this->configs[$config->getName()] = $config;

        return $this;
    }

    function selectDb(string $name):FastDb
    {
        return $this;
    }

    function execQuery(QueryBuilder $queryBuilder)
    {

    }
}