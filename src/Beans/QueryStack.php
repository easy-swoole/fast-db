<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\Mysqli\QueryBuilder;

class QueryStack
{
    public string $connectionName;

    public ?QueryBuilder $query;

    public ?string $rawQuery;

    public float $startTime;

    /** 单调时钟计算的查询耗时，单位为秒。 */
    public float $duration = 0.0;

    public float $endTime;
}