<?php

namespace EasySwoole\FastDb\Internal;

/** 查询输出选项，与 SQL 构建器的执行状态分别管理。 */
final class FieldSelection
{
    public ?array $fields = null;
    public ?array $hideFields = null;
    public bool $persistFieldLimit = false;
}
