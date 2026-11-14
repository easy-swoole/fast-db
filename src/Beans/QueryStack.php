<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\Internal\SqlSnapshot;

final class QueryStack
{
    public function __construct(
        public readonly string $type,
        public readonly string $connectionName,
        public readonly float $startTime,
        public readonly float $endTime,
        public readonly float $duration,
        public readonly ?\Throwable $exception = null,
        public readonly ?string $rawQuery = null,
        private readonly ?SqlSnapshot $sql = null,
    ) {}

    public function getLastQuery(): ?string
    {
        return $this->sql?->getLastQuery() ?? $this->rawQuery;
    }

    public function getLastPrepareQuery(): ?string
    {
        return $this->sql?->getLastPrepareQuery() ?? $this->rawQuery;
    }

    public function getLastBindParams(): array
    {
        return $this->sql?->getLastBindParams() ?? [];
    }
}
