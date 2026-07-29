<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;

class ListResult extends ArrayList
{

    private ?int $totalCount = null;

    function __construct(array $data,int|null $totalCount = null)
    {
        parent::__construct($data);
        $this->totalCount = $totalCount;
    }

    function totalCount():?int
    {
        return $this->totalCount;
    }

}