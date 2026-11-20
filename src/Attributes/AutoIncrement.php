<?php

namespace EasySwoole\FastDb\Attributes;

use Attribute;

/** 声明 insert() 接收自增 ID 的映射字段。 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class AutoIncrement
{
}
