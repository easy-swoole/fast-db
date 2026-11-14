<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Exception;

use EasySwoole\FastDb\Internal\SqlSnapshot;
use EasySwoole\Mysqli\QueryBuilder;

final class TimeoutException extends Exception
{
    private readonly SqlSnapshot $sql;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $rawSql = null,
        ?QueryBuilder $builder = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->sql = new SqlSnapshot($rawSql, $builder);
    }

    public function getRawSql(): ?string
    {
        return $this->rawSql;
    }

    public function getLastQuery(): ?string
    {
        return $this->sql->getLastQuery();
    }

    public function getLastPrepareQuery(): ?string
    {
        return $this->sql->getLastPrepareQuery();
    }

    public function getLastBindParams(): array
    {
        return $this->sql->getLastBindParams();
    }
}
