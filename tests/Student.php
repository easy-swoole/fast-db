<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Attributes\Relate;

class Student extends AbstractEntity
{

    #[Property(isPrimaryKey: true)]
    public int $id;
    #[Property]
    public string $name;

    function tableName(): string
    {
        return 'student';
    }


    #[Relate(
        targetEntity: StudentInfo::class
    )]
    function studentInfo()
    {
        $this->relateOne();
    }
}