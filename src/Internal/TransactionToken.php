<?php

namespace EasySwoole\FastDb\Internal;

/** 只由仍在执行的操作保存，避免为历史事务维护无限增长的状态表。 */
final class TransactionToken
{
    private ?bool $committed = null;
    public function complete(bool $committed): void { $this->committed = $committed; }
    public function wasRolledBack(): bool { return $this->committed === false; }
}
