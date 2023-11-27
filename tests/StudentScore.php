<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

class StudentScore extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $scoreId;

    #[Property]
    public int $studentId;

    #[Property]
    public int $courseId;

    #[Property]
    public int $score;

    #[Property]
    public ?string $extraMark;

    function tableName(): string
    {
        return 'student_score';
    }
}