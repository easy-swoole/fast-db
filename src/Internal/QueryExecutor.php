<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Mysql\Connection;
use EasySwoole\FastDb\Mysql\QueryResult;
use EasySwoole\Mysqli\QueryBuilder;

/** 统一采集查询快照和异常；所有通知均发生在连接级元数据保存之后。 */
final class QueryExecutor
{
    private ?\WeakMap $totals = null;
    private static ?\WeakMap $completionObservers = null;

    /** @internal One execution consumes its scoped listeners before public observers can reenter. */
    public static function onComplete(QueryBuilder $builder, callable $callback): \Closure
    {
        self::$completionObservers ??= new \WeakMap();
        $entry = (object) ['callback' => $callback, 'previous' => self::$completionObservers[$builder] ?? null, 'result' => null];
        self::$completionObservers[$builder] = $entry;
        return static function (bool $discard = false) use ($builder, $entry): void {
            if ((self::$completionObservers[$builder] ?? null) !== $entry) { return; }
            if (!$discard && $entry->previous !== null) { self::$completionObservers[$builder] = $entry->previous; }
            else { unset(self::$completionObservers[$builder]); }
        };
    }

    /** @internal Called by Connection before its observer, or by execute for custom connections. */
    public static function complete(QueryBuilder $builder, QueryResult|bool|array $value, Connection $client, ?float $startedAt = null, ?callable $deferCountObserver = null): void
    {
        $entry = self::$completionObservers[$builder] ?? null;
        if ($entry === null) { return; }
        unset(self::$completionObservers[$builder]);
        if ($value instanceof QueryResult) {
            $result = $value;
        } else {
            $result = $entry->result ?? new QueryResult($startedAt ?? microtime(true));
            $result->setConnectionName($client->connectionName ?? 'default');
            $result->setResult($value);
            $result->setAffectedRows($client->getLastAffectRows());
            $result->setInsertId($client->getLastInsertId());
        }
        do {
            ($entry->callback)($result, $client, $deferCountObserver);
            $entry = $entry->previous;
        } while ($entry !== null);
    }

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
        $cancelCompletion = null;
        if ($builder !== null) {
            $cancelCompletion = self::onComplete($builder,
                function (QueryResult $completed, Connection $connection, ?callable $deferCountObserver = null) use ($result, $builder, $name, &$count, &$timed, &$mainSucceeded): void {
                    $result->setResult($completed->getResult());
                    $result->setAffectedRows($completed->getAffectedRows());
                    $result->setInsertId($completed->getInsertId());
                    $result->setQueryBuilder(clone $builder);
                    $result->finishTiming();
                    $timed = $mainSucceeded = true;
                    if (in_array('SQL_CALC_FOUND_ROWS', $result->getQueryBuilder()->getLastQueryOptions(), true)) {
                        $read = function () use ($connection, $name, $result, &$count): QueryResult {
                            return $this->readTotal($connection, $name, $result, $count);
                        };
                        if ($deferCountObserver === null) { $read(); }
                        else {
                            $deferCountObserver($read, function (?\Throwable $error) use ($result, &$count): void {
                                if ($this->totals !== null) { unset($this->totals[$result]); }
                                if ($error === null) { $count = null; return; }
                                if ($count !== null) {
                                    $count->setResult(null);
                                    $count->setAffectedRows(null);
                                    $count->setInsertId(null);
                                    $count->setException($error);
                                }
                            });
                        }
                    }
                });
            self::$completionObservers[$builder]->result = $result;
        }
        try {
            $result->setResult($operation());
            $result->setAffectedRows($client->getLastAffectRows());
            $result->setInsertId($client->getLastInsertId());
            if (!$timed) { $result->finishTiming(); $timed = true; }
            $mainSucceeded = true;
            if ($builder !== null) { self::complete($builder, $result, $client); }
            $executedBuilder = $result->getQueryBuilder() ?? $builder;
            if ($count === null && $executedBuilder !== null && in_array('SQL_CALC_FOUND_ROWS', $executedBuilder->getLastQueryOptions(), true)) {
                $this->readTotal($client, $name, $result, $count);
            }
        } catch (\Throwable $error) {
            $failure = $error;
            if (!$mainSucceeded) { $result->setException($error); }
            throw $error;
        } finally {
            // A failed operation must not leave its entity listener available to callback queries.
            if ($cancelCompletion !== null) { $cancelCompletion(true); }
            if (!$timed) { $result->finishTiming(); }
            $result->setRawSql($sql);
            if ($builder !== null && $result->getQueryBuilder() === null) { $result->setQueryBuilder(clone $builder); }
            try {
                $notify($result);
                if ($count !== null) { $notify($count); }
            } catch (\Throwable $callbackError) {
                if ($failure === null) { throw $callbackError; }
            }
        }
        return $result;
    }

    private function readTotal(Connection $client, string $name, QueryResult $main, ?QueryResult &$count): QueryResult
    {
        $count = $this->execute($client, $name, static fn () => $client->rawQuery('SELECT FOUND_ROWS() as count'),
            static function (QueryResult $value) use (&$count): void { $count = $value; }, null, 'SELECT FOUND_ROWS() as count');
        $this->totals ??= new \WeakMap();
        $this->totals[$main] = $count;
        return $count;
    }

}
