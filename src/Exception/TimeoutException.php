<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Exception;

use EasySwoole\Mysqli\QueryBuilder;

final class TimeoutException extends Exception
{
    public ?string $rawSql = null;
    public ?QueryBuilder $queryBuilder = null;

    public function getRawSql(): ?string
    {
        return $this->rawSql;
    }

    public function getQueryBuilder(): ?QueryBuilder
    {
        return $this->queryBuilder;
    }
}
