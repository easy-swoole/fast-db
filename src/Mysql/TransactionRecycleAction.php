<?php

namespace EasySwoole\FastDb\Mysql;

/** 连接归还池时，对仍在进行的事务执行的操作。 */
enum TransactionRecycleAction: string
{
    case Commit = 'commit';
    case Rollback = 'rollback';
    case None = 'none';
}
