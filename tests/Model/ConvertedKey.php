<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests\Model;

use EasySwoole\FastDb\AbstractInterface\ConvertObjectInterface;

/** 模拟以字符串存储、可替换或原地修改的主键值对象。 */
class ConvertedKey implements ConvertObjectInterface
{
    public function __construct(public string $value) {}
    public static function toObject(mixed $data): object { return new static((string)$data); }
    public function toValue(): string { return $this->value; }
}
