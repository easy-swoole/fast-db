<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Beans\Json;

class Address extends Json
{
    public $city;
    public $province;

    public $detail;
}