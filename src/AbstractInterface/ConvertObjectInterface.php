<?php

namespace EasySwoole\FastDb\AbstractInterface;

interface ConvertObjectInterface
{
    public static function toObject(mixed $data):object;
    public function toValue();
}