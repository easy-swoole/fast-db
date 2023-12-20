<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

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
