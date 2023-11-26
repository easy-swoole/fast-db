<?php

namespace EasySwoole\FastDb\AbstractInterface;

interface ConvertObjectInterface
{
    function restore(mixed $data);
    function toValue();
}