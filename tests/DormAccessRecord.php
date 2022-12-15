<?php

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Entity;

class DormAccessRecord extends Entity
{

    function tableName(): string
    {
        return "student_dorm_access_record";
    }
}