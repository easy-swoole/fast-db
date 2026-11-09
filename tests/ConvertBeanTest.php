<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\ConvertBean;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\Spl\Attribute\ConvertBean as NestedBean;
use PHPUnit\Framework\TestCase;

final class ConvertBeanTest extends TestCase
{
    public function testArrayDataRestoresRequiredNestedBean(): void
    {
        $data = ['name' => 'Alice', 'address' => ['city' => 'Xiamen']];
        $bean = RequiredNestedUserBean::toObject($data);

        $this->assertInstanceOf(RequiredNestedAddressBean::class, $bean->address);
        $this->assertSame('Alice', $bean->name);
        $this->assertSame('Xiamen', $bean->address->city);
        $this->assertSame($data, json_decode($bean->toValue(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testJsonDataRestoresRequiredNestedBean(): void
    {
        $data = ['name' => 'Bob', 'address' => ['city' => 'Shanghai']];
        $bean = RequiredNestedUserBean::toObject(json_encode($data, JSON_THROW_ON_ERROR));

        $this->assertInstanceOf(RequiredNestedAddressBean::class, $bean->address);
        $this->assertSame('Bob', $bean->name);
        $this->assertSame('Shanghai', $bean->address->city);
        $this->assertSame($data, json_decode($bean->toValue(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTypedListInitializationRestoresRequiredNestedBeans(): void
    {
        $data = [
            ['name' => 'Alice', 'address' => ['city' => 'Xiamen']],
            ['name' => 'Bob', 'address' => ['city' => 'Shanghai']],
        ];
        $list = RequiredNestedUserList::toObject(json_encode($data, JSON_THROW_ON_ERROR));

        $this->assertSame(2, $list->length());
        $this->assertInstanceOf(RequiredNestedUserBean::class, $list->first());
        $this->assertSame('Xiamen', $list->first()->address->city);
        $this->assertSame('Shanghai', $list->toArray()[1]->address->city);
        $this->assertSame($data, json_decode($list->toValue(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testTypedListAppendRestoresRequiredNestedBean(): void
    {
        $data = ['name' => 'Alice', 'address' => ['city' => 'Xiamen']];
        $list = new RequiredNestedUserList([]);
        $list->append($data);

        $this->assertSame(1, $list->length());
        $this->assertInstanceOf(RequiredNestedUserBean::class, $list->first());
        $this->assertSame('Xiamen', $list->first()->address->city);
        $this->assertSame([$data], json_decode($list->toValue(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testInitializeSeesRestoredData(): void
    {
        $bean = InitializationObserverBean::toObject(['name' => 'loaded before initialize']);

        $this->assertSame('loaded before initialize', $bean->name);
        $this->assertSame('loaded before initialize', $bean->nameSeenDuringInitialize);
    }
}

class RequiredNestedAddressBean extends ConvertBean
{
    public string $city;
}

class RequiredNestedUserBean extends ConvertBean
{
    public string $name = '';

    #[NestedBean(RequiredNestedAddressBean::class)]
    public RequiredNestedAddressBean $address;
}

class RequiredNestedUserList extends ConvertList
{
    protected static function itemClass(): ?string
    {
        return RequiredNestedUserBean::class;
    }
}

class InitializationObserverBean extends ConvertBean
{
    public ?string $name = null;

    public ?string $nameSeenDuringInitialize = null;

    protected function initialize(): void
    {
        $this->nameSeenDuringInitialize = $this->name;
    }
}
