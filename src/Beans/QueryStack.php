<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\Mysqli\QueryBuilder;

class QueryStack
{
    public string $connectionName;

    public ?QueryBuilder $query;

    public ?string $rawQuery;

    public float $startTime;

    public float $endTime;
}