<?php
declare(strict_types=1);
/**
 * This file is part of EasySwoole.
 *
 * @link     https://www.easyswoole.com
 * @document https://www.easyswoole.com
 * @contact  https://www.easyswoole.com/Preface/contact.html
 * @license  https://github.com/easy-swoole/easyswoole/blob/3.x/LICENSE
 */

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

class StudentInfoModel extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
    public int $id;

    #[Property()]
    public int $studentId;

    #[Property(
        convertObject: Address::class
    )]
    public Address $address;

    #[Property]
    public ?string $note;

    #[Property(
        convertObject: SexEnum::class
    )]
    public SexEnum $sex;

    function tableName(): string
    {
        return "student_info";
    }
}
