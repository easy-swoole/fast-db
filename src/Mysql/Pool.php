<?php

namespace EasySwoole\FastDb\Mysql;

use EasySwoole\Mysqli\Config;
use EasySwoole\Pool\AbstractPool;

class Pool extends AbstractPool
{
    protected function createObject()
    {
        //转错误，避免进程异常退出
        try{
            $config = new Config($this->getConfig()->toArray());
            $con =  new Connection($config);
            $con->connect();
            //用于AutoPing
            $con->__lastPingTime = 0;
            return $con;
        }catch (\Throwable $throwable){
            /** @var \EasySwoole\FastDb\Config $config */
            $config = $this->getConfig();
            trigger_error("connection {$config->getName()} ".$throwable->getMessage());
        }
    }

    /**
     * @param int|null $num
     * @return int
     * 屏蔽在定时周期检查的时候，出现连接创建出错，导致进程退出。
     */
    public function keepMin(?int $num = null): int
    {
        try{
            return parent::keepMin($num);
        }catch (\Throwable $throwable){
            /** @var \EasySwoole\FastDb\Config $config */
            $config = $this->getConfig();
            trigger_error("connection {$config->getName()} ".$throwable->getMessage());
            return $this->status(true)['created'];
        }
    }

    protected function itemIntervalCheck($item): bool
    {
        /** @var \EasySwoole\FastDb\Config $config */
        $config = $this->getConfig();
        /**
         *  auto ping是为了保证在 idleMaxTime周期内的可用性 （如果超出了周期还没使用，则代表现在进程空闲，可以先回收）
         */
        if($config->getAutoPing() > 0 && (time() - $item->__lastPingTime > $config->getAutoPing())){
            try{
                //执行一个sql触发活跃信息
                $item->rawQuery('select 1');
                return true;
            }catch (\Throwable $throwable){
                //异常说明该链接出错了，return false 进行回收
                return false;
            }
        }else{
            return true;
        }
    }

}