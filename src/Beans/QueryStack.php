<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\Mysqli\QueryBuilder;

class QueryStack
{
    /** query 为 SQL 执行，connectionAcquire 为独立连接借用事件。 */
    public string $type = 'query';

    public ?\Throwable $exception = null;

    public string $connectionName;

    public ?QueryBuilder $query;

    public ?string $rawQuery;

    public float $startTime;

    /** 单调时钟计算的事件耗时，单位为秒；由 type 区分查询执行或连接获取。 */
    public float $duration = 0.0;

    public float $endTime;
}
