<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;

enum SexEnum implements ConvertObjectInterface
{
    case UNKNUWN;
    case MALE;

    case FEMAILE;

    public static function toObject(mixed $data): object
    {
        switch ($data){
            case 1:{
                return self::MALE;
            }
            case 2:{
                return self::FEMAILE;
            }
            default:{
                return self::UNKNUWN;
            }
        }
    }


    function toValue()
    {
        return match ($this){
            self::MALE=>1,
            self::FEMAILE=>2,
            default=>0
        };
    }
}