<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\PrimaryKey;
use EasySwoole\FastDb\Attributes\Property;

/**
 * @property int $id 自增主键
 * @property string|null $name 姓名
 * @property int|null $status 状态
 * @property int|null $score 分数
 * @property int|null $sex 性别
 * @property string|null $address 地址
 * @property string|null $email 邮箱
 */
class User extends AbstractEntity
{
    #[Property]
    #[PrimaryKey]
    public int $id;
    #[Property]
    public ?string $name;
    #[Property]
    public ?int $status;
    #[Property]
    public ?int $score;
    #[Property]
    public ?int $sex;
    #[Property]
    public ?string $address;
    #[Property]
    public ?string $email;

    public function tableName(): string
    {
        return 'easyswoole_user';
    }
}
