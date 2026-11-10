<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\AbstractInterface\AbstractEntity;
use EasySwoole\FastDb\AbstractInterface\ConvertBean;
use EasySwoole\FastDb\AbstractInterface\ConvertList;
use EasySwoole\FastDb\Attributes\Property;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StrictConversionTest extends TestCase
{
    public static function invalidInputs(): iterable
    {
        foreach ([StrictJsonBean::class, ConvertList::class] as $class) {
            foreach (['{bad', '', "\xff", str_repeat('[', 513) . str_repeat(']', 513)] as $index => $input) {
                yield $class . ' malformed ' . $index => [$class, $input, \JsonException::class];
            }
            foreach (['null', 'false', '0', '"text"', false, 0, new \stdClass()] as $index => $input) {
                yield $class . ' unsupported ' . $index => [$class, $input, \InvalidArgumentException::class];
            }
        }
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsNotSilentlyReplaced(string $class, mixed $input, string $exception): void
    {
        $this->expectException($exception);
        $class::toObject($input);
    }

    public function testNullAndValidEmptyContainersKeepTheirDefaults(): void
    {
        foreach ([null, [], '{}', '[]'] as $input) {
            $this->assertSame('default', StrictJsonBean::toObject($input)->name);
            $this->assertSame([], ConvertList::toObject($input)->toArray());
        }
        $this->assertSame('loaded', StrictJsonBean::toObject('{"name":"loaded"}')->name);
        $this->assertSame(['key' => 1], ConvertList::toObject('{"key":1}')->toArray());
    }

    public function testBusinessSubclassCanExplicitlyRecoverFromMalformedJson(): void
    {
        $this->assertInstanceOf(TolerantJsonList::class, TolerantJsonList::toObject('{bad'));
        $this->assertSame([], TolerantJsonList::toObject('{bad')->toArray());
        $this->assertSame([1], TolerantJsonList::toObject('[1]')->toArray());
        $this->assertInstanceOf(TolerantJsonBean::class, TolerantJsonBean::toObject('{bad'));
        $this->assertSame('default', TolerantJsonBean::toObject('{bad')->name);
        $this->assertSame('loaded', TolerantJsonBean::toObject('{"name":"loaded"}')->name);
    }

    public function testJsonOnlyFallbackDoesNotHideUnsupportedData(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TolerantJsonList::toObject('false');
    }

    public function testJsonOnlyFallbackDoesNotHideBeanInitializationErrors(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('business initialization failed');
        FailingTolerantJsonBean::toObject('{}');
    }

    public static function convertedFields(): array { return [['items'], ['profile']]; }

    #[DataProvider('convertedFields')]
    public function testEntityAssignmentPropagatesParsingFailureAndKeepsExistingValue(string $field): void
    {
        $entity = new StrictJsonEntity(['items' => '[1]', 'profile' => '{"name":"loaded"}']);
        $before = $entity->{$field};
        try { $entity->setData([$field => '{bad']); $this->fail('Expected JSON parse error'); }
        catch (\JsonException) {}
        $this->assertSame($before, $entity->{$field});
        $this->assertTrue($entity->update());
        $entity->setData([$field => null]);
        $this->assertNull($entity->{$field});
    }
}

class StrictJsonBean extends ConvertBean
{
    public string $name = 'default';
}

class TolerantJsonList extends ConvertList
{
    public static function toObject(mixed $data): static
    {
        try { return parent::toObject($data); }
        catch (\JsonException) { return new static([]); }
    }
}

class TolerantJsonBean extends StrictJsonBean
{
    public static function toObject(mixed $data): static
    {
        try { return parent::toObject($data); }
        catch (\JsonException) { return new static([]); }
    }
}

class FailingTolerantJsonBean extends TolerantJsonBean
{
    protected function initialize(): void { throw new \LogicException('business initialization failed'); }
}

class StrictJsonEntity extends AbstractEntity
{
    #[Property(convertObject: ConvertList::class)] public ?ConvertList $items;
    #[Property(convertObject: StrictJsonBean::class)] public ?StrictJsonBean $profile;
    public function tableName(): string { return 'strict_json_entity'; }
}
