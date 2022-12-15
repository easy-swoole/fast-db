<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Entity;

class Student extends Entity
{

    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public string $name;

    function tableName(): string
    {
        return "student";
    }
    #[Relate(
        targetEntity: StudentInfo::class,
        targetProperty: "studentId"
    )]
    function info():?StudentInfo
    {
        return $this->relate();
    }
}