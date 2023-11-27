<?php

namespace EasySwoole\FastDb\Beans;

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
    function __construct(?int $page = null,bool $withTotalCount = false,int $pageSize = 10)
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

    function toLimitArray():array
    {
        if($this->page >= 1){
            return [
                ($this->page - 1)*$this->pageSize,$this->pageSize
            ];
        }else{
            return [$this->pageSize];
        }
    }
}