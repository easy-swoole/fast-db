<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Attributes\ConvertJson;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\FastDb\Entity;

class StudentInfo extends Entity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property()]
    public int $studentId;

    #[Property]
    #[ConvertJson(Address::class)]
    public Address $address;

    #[Property]
    public ?string $note;

    function tableName(): string
    {
        return "student_info";
    }
}