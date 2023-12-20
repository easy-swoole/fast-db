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
use EasySwoole\FastDb\Attributes\Relate;

/**
 * Class StudentModel
 *
 * @package EasySwoole\FastDb\Tests\Model
 * @property int    $id
 * @property string $name
 */
class StudentModel extends AbstractEntity
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
        targetEntity: StudentInfoModel::class,
        targetProperty: 'studentId'
    )]
    function studentInfo()
    {
        return $this->relateOne();
    }

    #[Relate(
        targetEntity: StudentScoreModel::class,
        targetProperty: "studentId"
    )]
    function score()
    {
        return $this->relateMany();
    }
}
