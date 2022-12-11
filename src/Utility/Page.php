<?php

namespace EasySwoole\FastDb\Utility;

class Page
{
    private int $page = 1;
    private int $pageSize = 10;
    private bool $withTotalCount = false;

    function __construct(int $page,int $pageSize,bool $withTotalCount)
    {
        $this->page = $page;
        $this->pageSize = $pageSize;
        $this->withTotalCount = $withTotalCount;
    }

    /**
     * @return int
     */
    public function getPage(): int
    {
        return $this->page;
    }

    /**
     * @return int
     */
    public function getPageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * @return bool
     */
    public function isWithTotalCount(): bool
    {
        return $this->withTotalCount;
    }
}