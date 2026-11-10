<?php

namespace EasySwoole\FastDb\Internal;

use EasySwoole\FastDb\Beans\QueryStack;
use EasySwoole\FastDb\Mysql\QueryResult;
use Swoole\Coroutine;

/** @internal 管理可选查询回调，以及按协程隔离、具有容量上限的诊断事件。 */
final class QueryDiagnostics
{
    private bool $enabled = false;
    private int $maxStackSize = 256;
    private mixed $onQuery = null;
    private array $stacks = [];
    /** 记录每个满环中最旧事件的槽位；未满时从零开始顺序追加。 */
    private array $offsets = [];

    public function configure(bool $enabled, int $maxStackSize = 256): void
    {
        if ($maxStackSize < 1) {
            throw new \InvalidArgumentException('Query stack capacity must be greater than zero');
        }
        if ($maxStackSize !== $this->maxStackSize) {
            foreach ($this->stacks as $cid => $stack) {
                $stack = $this->inOrder($cid);
                $this->stacks[$cid] = count($stack) > $maxStackSize ? array_slice($stack, -$maxStackSize) : $stack;
            }
            $this->offsets = [];
            $this->maxStackSize = $maxStackSize;
        }
        $this->enabled = $enabled;
    }

    public function setOnQuery(callable $callback): void
    {
        $this->onQuery = $callback;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function stack(int $cid, ?int $index = null): null|array|QueryStack
    {
        if (!isset($this->stacks[$cid])) { return null; }
        if ($index === null) { return $this->inOrder($cid); }
        $size = count($this->stacks[$cid]);
        if ($index < 0) { $index += $size; }
        return $index >= 0 && $index < $size
            ? $this->stacks[$cid][(($this->offsets[$cid] ?? 0) + $index) % $size]
            : null;
    }

    public function clear(int $cid): void
    {
        unset($this->stacks[$cid], $this->offsets[$cid]);
    }

    public function notify(QueryResult $result): void
    {
        if ($this->enabled) {
            $stack = new QueryStack();
            $stack->connectionName = $result->getConnectionName();
            $stack->startTime = $result->getStartTime();
            $stack->endTime = $result->getEndTime();
            $stack->duration = $result->getDuration();
            $stack->exception = $result->getException();
            $stack->query = $result->getQueryBuilder();
            $stack->rawQuery = $result->getRawSql();
            $this->append($stack);
        }
        if ($this->onQuery !== null) {
            ($this->onQuery)($result);
        }
    }

    public function recordAcquire(string $name, float $startTime, float $endTime, float $duration,
        ?\Throwable $error = null): void
    {
        if (!$this->enabled) { return; }
        $stack = new QueryStack();
        $stack->type = 'connectionAcquire';
        $stack->connectionName = $name;
        $stack->startTime = $startTime;
        $stack->endTime = $endTime;
        $stack->duration = $duration;
        $stack->exception = $error;
        $stack->query = null;
        $stack->rawQuery = null;
        $this->append($stack);
    }

    private function append(QueryStack $stack): void
    {
        $cid = Coroutine::getCid();
        if (count($this->stacks[$cid] ?? []) < $this->maxStackSize) {
            $this->stacks[$cid][] = $stack;
            return;
        }
        $offset = $this->offsets[$cid] ?? 0;
        $this->stacks[$cid][$offset] = $stack;
        $this->offsets[$cid] = ($offset + 1) % $this->maxStackSize;
    }

    private function inOrder(int $cid): array
    {
        $stack = $this->stacks[$cid];
        $offset = $this->offsets[$cid] ?? 0;
        return $offset === 0 ? $stack : array_merge(array_slice($stack, $offset), array_slice($stack, 0, $offset));
    }
}
