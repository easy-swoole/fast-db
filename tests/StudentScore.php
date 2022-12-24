<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

class StudentScore extends Entity
{

    #[Property(isPrimaryKey: true)]
    public int $scoreId;

    #[Property]
    public int $studentId;

    #[Property]
    public int $courseId;

    #[Property]
    public int $score;

    function tableName(): string
    {
        return "student_score";
    }
}