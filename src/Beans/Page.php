<?php

namespace EasySwoole\FastDb\Beans;

use EasySwoole\Mysqli\QueryBuilder\Pagination;

class Page
{
    private ?int $page = 1;
    private int $pageSize = 10;
    private bool $withTotalCount = false;

    /**
     * @param int|null $page 当为null的时候，标示取limit $pageSize 数量
     * @param bool $withTotalCount
     * @param int $pageSize
     */
    function __construct(int|null $page = null,bool $withTotalCount = false,int $pageSize = 10)
    {
        $this->page = $page;
        $this->pageSize = $pageSize;
        $this->withTotalCount = $withTotalCount;
    }

    /**
     * @return int|null
     */
    public function getPage(): ?int
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

    function toLimitArray():array
    {
        return $this->page === null
            ? [$this->pageSize]
            : [Pagination::offset($this->pageSize, $this->page), $this->pageSize];
    }
}
