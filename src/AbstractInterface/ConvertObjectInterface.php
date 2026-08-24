<?php

namespace EasySwoole\FastDb\AbstractInterface;

use EasySwoole\Spl\AbstractInterface\ConvertBeanInterface;

interface ConvertObjectInterface extends ConvertBeanInterface
{
    /**
     * 当调用toArray。或者是要插入、更新数据库时候，会调用toValue
     */
}