<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Hook\OnInsert;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

#[OnInsert([
    StudentDorm::class,"checkHash"
])]
class StudentDorm extends Entity
{
    #[Property(isPrimaryKey: true)]
    public string $hash;

    #[Property()]
    public int $studentId;

    #[Property]
    public int $dormId;

    function tableName(): string
    {
        return "student_dorm_map";
    }

    public static function checkHash(StudentDorm $dorm)
    {

    }
}