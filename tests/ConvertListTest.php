<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertBean;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Property;
use EasySwoole\Spl\SplBean;
use PHPUnit\Framework\TestCase;

final class ConvertListTest extends TestCase
{
    public function testJsonAndArrayItemsBecomeBeans(): void
    {
        $list = UserBeanList::toObject('[{"name":"张三"},{"name":"李四"}]');
        $this->assertInstanceOf(ListUserBean::class, $list->first());
        $this->assertSame('张三', $list->first()->name);
        $this->assertSame('李四', $list->toArray()[1]->name);
        $this->assertSame([['name' => '张三'], ['name' => '李四']], json_decode($list->toValue(), true));
        $this->assertSame('王五', (new UserBeanList([['name' => '王五']]))->first()->name);
    }

    public function testAppendAndMutationRoundTrip(): void
    {
        $bean = new ListUserBean(['name' => '甲']);
        $list = new UserBeanList([$bean]);
        $list->append(['name' => '乙']);
        $list->append($bean);
        $list->append(null);
        $this->assertSame($bean, $list->first());
        $this->assertSame(3, $list->length());
        $bean->name = '丙';
        $this->assertSame([['name' => '丙'], ['name' => '乙'], ['name' => '丙']], json_decode($list->toValue(), true));
        $list->remove($bean, true);
        $this->assertSame('[{"name":"乙"}]', json_encode(json_decode($list->toValue(), true), JSON_UNESCAPED_UNICODE));
        $this->assertSame('乙', $list->first()->name);
        $this->assertSame('乙', UserBeanList::toObject($list->toValue())->first()->name);
    }

    public function testBatchConstructionResolvesTheItemTypeOnlyOnce(): void
    {
        CountingBeanList::$resolutions = 0;
        $list = new CountingBeanList(array_fill(0, 1000, ['name' => '甲']));
        $this->assertSame(1, CountingBeanList::$resolutions);
        $this->assertSame(1000, $list->length());
        $this->assertInstanceOf(ListUserBean::class, $list->first());
        $bean = new ListUserBean(['name' => '乙']);
        $list->append($bean);
        $this->assertSame(2, CountingBeanList::$resolutions);
        $this->assertSame($bean, $list->toArray()[1000]);
        $list->append(['name' => '丙']);
        $this->assertSame(3, CountingBeanList::$resolutions);
        $this->assertSame('丙', $list->toArray()[1001]->name);
        $list->append(null);
        $this->assertSame(3, CountingBeanList::$resolutions);
        $this->assertSame(1002, $list->length());
    }

    public function testPlainSplBeanIsSupported(): void
    {
        $list = PlainBeanList::toObject([['name' => '甲']]);
        $this->assertInstanceOf(PlainListBean::class, $list->first());
        $this->assertSame([['name' => '甲']], json_decode($list->toValue(), true));
        $list->append(['name' => '乙']);
        $this->assertSame('乙', $list->toArray()[1]->name);
    }

    public function testEntityAssignmentSerializationAndNull(): void
    {
        $entity = new BeanListEntity(['users' => '[{"name":"甲"}]']);
        $this->assertInstanceOf(ListUserBean::class, $entity->users->first());
        $entity->users->first()->name = '乙';
        $this->assertSame([['name' => '乙']], json_decode($entity->toArray()['users'], true));
        $entity->setData(['users' => [['name' => '丙']]]);
        $this->assertSame('丙', $entity->users->first()->name);
        $entity->setData(['users' => null]);
        $this->assertNull($entity->users);
    }

    public function testBeanMutationParticipatesInDirtyTracking(): void
    {
        $entity = new BeanListEntity(['users' => '[{"name":"甲"}]']);
        $state = (new \ReflectionProperty(AbstractEntity::class, 'entityState'))->getValue($entity);
        $properties = \EasySwoole\FastDb\Utility\ReflectionCache::getInstance()
            ->parseEntity(BeanListEntity::class)->allProperties();
        $this->assertSame([[], []], $state->changes($entity, $properties));
        $entity->users->first()->name = '乙';
        [$data, $baseline] = $state->changes($entity, $properties);
        $this->assertSame([['name' => '乙']], json_decode($data['users'], true));
        $state->accept($baseline);
        $this->assertSame([[], []], $state->changes($entity, $properties));
        $entity->users->first()->name = '甲';
        [$data] = $state->changes($entity, $properties);
        $this->assertSame([['name' => '甲']], json_decode($data['users'], true));
    }

    public function testOrdinaryListsRemainUnconverted(): void
    {
        $list = ConvertList::toObject('[1,{"name":"甲"}]');
        $list->append(false);
        $this->assertSame([1, ['name' => '甲'], false], $list->toArray());
        $this->assertSame([], UserBeanList::toObject(null)->toArray());
    }

    public function testFirstUsesTheFirstRemainingKeyWithoutRenumbering(): void
    {
        $list = new ConvertList([4 => 10, 'named' => 20, 9 => 30]);
        $this->assertSame(10, $list->first());
        $list->remove(10, true);
        $this->assertSame(20, $list->first());
        $this->assertSame(['named' => 20, 9 => 30], $list->toArray());
        $list->remove(20, true);
        $this->assertSame(30, $list->first());
        $list->remove(30, true);
        $this->assertNull($list->first());
    }

    public static function encodingFailures(): array
    {
        return [['invalid utf8', "\xFF"], ['NaN', NAN], ['infinity', INF]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodingFailures')]
    public function testInvalidJsonValuesRaiseAnEncodingError(string $kind, mixed $value): void
    {
        $list = new ConvertList([$value]);
        $this->expectException(\JsonException::class);
        $list->toValue();
    }

    public function testInvalidItemIsRejected(): void
    {
        $list = new UserBeanList([]);
        $this->expectException(\InvalidArgumentException::class);
        $list->append('invalid');
    }

    public function testInvalidBeanTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InvalidBeanList([]);
    }
}

class ListUserBean extends ConvertBean
{
    public string $name = '';
}

class UserBeanList extends ConvertList
{
    protected static function itemClass(): ?string { return ListUserBean::class; }
}

class PlainListBean extends SplBean
{
    public string $name = '';
}

class PlainBeanList extends ConvertList
{
    protected static function itemClass(): ?string { return PlainListBean::class; }
}

class InvalidBeanList extends ConvertList
{
    protected static function itemClass(): ?string { return \stdClass::class; }
}

class BeanListEntity extends AbstractEntity
{
    public function tableName(): string { return "bean_list_test"; }
    #[Property(convertObject: UserBeanList::class)]
    public ?UserBeanList $users;
}

class CountingBeanList extends UserBeanList
{
    public static int $resolutions = 0;
    protected static function itemClass(): ?string
    {
        self::$resolutions++;
        return parent::itemClass();
    }
}
