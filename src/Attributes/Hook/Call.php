<?php

namespace EasySwoole\FastDb\Attributes\Hook;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;

class Call
{
    const PARAM_CURRENT_ENTITY = 'PARAM_CURRENT_ENTITY';

    public mixed $callback;
    public array|null $params;

    function __construct(
        callable|string $callback,
        array|null $params = null
    )
    {
        $this->callback = $callback;
        $this->params = $params;
    }

    function buildEntityHookRuntimeParams(AbstractEntity|null $entity = null): array
    {
        if(empty($this->params)){
            return [$entity];
        }
        $temp = [];
        foreach($this->params as $param){
            if($param === self::PARAM_CURRENT_ENTITY){
                $param = $entity;
            }
            $temp[] = $param;
        }
        return $temp;
    }
}
