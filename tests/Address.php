<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Beans\Json;
use EasySwoole\FastDb\Attributes\ConvertJson;

class Address extends Json
{
    public $city;
    public $province;

    #[ConvertJson(AddressDetail::class)]
    public ?AddressDetail $detail;
}