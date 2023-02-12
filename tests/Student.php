<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;
use EasySwoole\FastDb\Entity;
use EasySwoole\Mysqli\QueryBuilder;

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
        return $this->relateOne();
    }

    function info2()
    {
        $r = new Relate(
            targetEntity: StudentInfo::class,
        );
        return $this->relateOne($r);
    }

    #[Relate(
        targetEntity: StudentScore::class,
        targetProperty: "studentId",
        returnAsTargetEntity: false
    )]
    function score()
    {
        return $this->relateMore();
    }
}