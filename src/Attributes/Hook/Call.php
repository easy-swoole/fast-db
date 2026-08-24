<?php

namespace EasySwoole\FastDb\Attributes\Hook;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;

class Call
{
    const PARAM_PROPERTY_VALUE = 'PARAM_PROPERTY_VALUE';

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

    function buildPropertyRuntimeParams(mixed $propertyVal,AbstractEntity|null $entity = null): array
    {
        if(empty($this->params)){
            return [$propertyVal];
        }
        $temp = [];
        foreach($this->params as $param){
            if($param === self::PARAM_PROPERTY_VALUE){
                $param = $propertyVal;
            }
            if($param === self::PARAM_CURRENT_ENTITY){
                $param = $entity;
            }
            $temp[] = $param;
        }
        return $temp;
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