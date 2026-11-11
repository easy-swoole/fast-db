<?php

namespace EasySwoole\FastDb;

/** 关联结果的数量类型，按需加载与预加载统一使用。 */
enum RelationType: string
{
    case One = 'one';
    case Many = 'many';
}
