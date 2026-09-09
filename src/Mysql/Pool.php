<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Config;
use EasySwoole\Pool\AbstractPool;
use EasySwoole\Pool\ObjectInterface;

class Pool extends AbstractPool
{
    protected function createObject(): ObjectInterface
    {
        /** @var \EasySwoole\FastDb\Config $poolConfig */
        $poolConfig = $this->getConfig();
        $config = new Config($poolConfig->toArray());
        $con = new Connection($config);
        $con->isForceRollback = $poolConfig->isIsForceRollback();
        $con->connect();
        return $con;
    }

    /**
     * @param int|null $num
     * @return int
     * 屏蔽在定时周期检查的时候，出现连接创建出错，导致进程退出。
     */
    public function keepMin(?int $num = null): int
    {
        $currentAdd = 0;
        if($num == null){
            $num = $this->getConfig()->getMinObjectNum();
        }
        if ($this->createdNum <= $num) {
            $left = $num - $this->createdNum;
            while ($left >= 1) {
                try {
                    if (!$this->initObject()) {
                        break;
                    }
                }catch (\Throwable $throwable){
                    // 非关键位置没必要抛出异常导致进程意外结束
                    /** @var \EasySwoole\FastDb\Config $config */
                    $config = $this->getConfig();
                    trigger_error("connection {$config->getName()} createObject() error,".$throwable->getMessage());
                    break;
                }

                $left--;
                $currentAdd++;
            }
        }
        return $currentAdd;
    }
}