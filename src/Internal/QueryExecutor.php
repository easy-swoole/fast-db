<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;

/** 统一采集查询快照和异常；所有通知均发生在连接级元数据保存之后。 */
final class QueryExecutor
{
    private ?\WeakMap $totals = null;

    public function total(QueryResult $result): ?int
    {
        return isset($this->totals[$result]) ? (int) ($this->totals[$result]->getResultOne()['count'] ?? 0) : null;
    }

    public function totalResult(QueryResult $result): ?QueryResult
    {
        return $this->totals[$result] ?? null;
    }

    public function execute(Connection $client, string $name, callable $operation, callable $notify,
        ?QueryBuilder $builder = null, ?string $sql = null): QueryResult
    {
        $result = new QueryResult(microtime(true));
        $result->setConnectionName($name);
        $count = null;
        $timed = false;
        $mainSucceeded = false;
        $failure = null;
        try {
            $result->setResult($operation());
            $result->setAffectedRows($client->getLastAffectRows());
            $result->setInsertId($client->getLastInsertId());
            $result->finishTiming();
            $timed = true;
            $mainSucceeded = true;
            if ($builder !== null && in_array('SQL_CALC_FOUND_ROWS', $builder->getLastQueryOptions(), true)) {
                // FOUND_ROWS 属于连接，必须在任何外部回调有机会执行其他 SQL 前读取。
                $count = $this->execute($client, $name, static fn () => $client->rawQuery('SELECT FOUND_ROWS() as count'),
                    static function (QueryResult $value) use (&$count): void { $count = $value; }, null, 'SELECT FOUND_ROWS() as count');
                $this->totals ??= new \WeakMap();
                $this->totals[$result] = $count;
            }
        } catch (\Throwable $error) {
            $failure = $error;
            if (!$mainSucceeded) { $result->setException($error); }
            throw $error;
        } finally {
            if (!$timed) { $result->finishTiming(); }
            $result->setRawSql($sql);
            if ($builder !== null) { $result->setQueryBuilder(clone $builder); }
            try {
                $notify($result);
                if ($count !== null) { $notify($count); }
            } catch (\Throwable $callbackError) {
                if ($failure === null) { throw $callbackError; }
            }
        }
        return $result;
    }
}
