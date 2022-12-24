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
        return $this->relate();
    }

    #[Relate(
        targetEntity: StudentScore::class,
        targetProperty: "studentId",
        relateType: Relate::RELATE_ONE_TO_MULTIPLE,
        returnAsTargetEntity: false
    )]
    function score()
    {
        return $this->relate(null,function (QueryBuilder $queryBuilder){
            $queryBuilder->join("course","student_score.courseId = course.courseId");
        });
    }
}