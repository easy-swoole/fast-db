<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\Attributes\Property;

/**
 * @property int $id increment id
 * @property string|null $name name
 * @property int|null $status status
 * @property int|null $score score
 * @property int|null $sex sex
 * @property string|null $address address
 * @property string|null $email email
 */
class User extends AbstractEntity
{
    #[Property(isPrimaryKey: true)]
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
