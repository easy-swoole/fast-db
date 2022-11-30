<?php

namespace EasySwoole\FastDb;

abstract class Entity
{


    final function __construct(){
        $this->reflection();
        $this->initialize();
    }

    protected array $properties = [];

    abstract function tableName():string;

    function toArray():array
    {
        return [];
    }

    protected function initialize(): void
    {

    }

    private function reflection(): void
    {

    }


}