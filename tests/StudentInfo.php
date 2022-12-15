<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

class StudentInfo extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property()]
    public int $studentId;

    #[Property]
    public ?string $address;

    function tableName(): string
    {
        return "student_info";
    }
}