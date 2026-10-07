<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Beans\ArrayList;
use PHPUnit\Framework\TestCase;

final class ArrayListTest extends TestCase
{
    public function testRepeatedAppendUsesNumericKeysAndIsIterable(): void
    {
        $list = new ArrayList([['id' => 1]]);
        $list[] = ['id' => 2];
        $list[] = ['id' => 3];
        $expected = [['id' => 1], ['id' => 2], ['id' => 3]];
        $this->assertCount(3, $list);
        $this->assertSame($expected, $list->list());
        $this->assertSame($expected, iterator_to_array($list));
        $this->assertSame($expected, iterator_to_array($list));
        $this->assertSame($expected, json_decode(json_encode($list), true));
    }

    public function testAppendToEmptyList(): void
    {
        $list = new ArrayList([]);
        $list[] = ['id' => 1];
        $this->assertSame([['id' => 1]], iterator_to_array($list));
        $this->assertSame(['id' => 1], $list[0]);
    }

    public function testIterationAfterUnsetPreservesRemainingKeys(): void
    {
        $list = new ArrayList([['id' => 1], ['id' => 2], ['id' => 3]]);
        unset($list[1]);
        $list[] = ['id' => 4];
        $this->assertSame([0 => ['id' => 1], 2 => ['id' => 3], 3 => ['id' => 4]], iterator_to_array($list));
        $this->assertCount(3, $list);
    }

    public function testExplicitKeysRemainIterable(): void
    {
        $list = new ArrayList([4 => ['id' => 1]]);
        $list['other'] = ['id' => 2];
        $list[4] = ['id' => 3];
        $this->assertSame([4 => ['id' => 3], 'other' => ['id' => 2]], iterator_to_array($list));
    }

    public function testNullElementDoesNotStopIteration(): void
    {
        $list = new ArrayList([null, ['id' => 1]]);
        $list[] = null;
        $this->assertSame([null, ['id' => 1], null], iterator_to_array($list));
    }

    public function testRemoveThenAppendRemainsIterable(): void
    {
        $list = new ArrayList([['id' => 1], ['id' => 2]]);
        $list->remove(0);
        $list[] = ['id' => 3];
        $this->assertSame([['id' => 2], ['id' => 3]], iterator_to_array($list));
    }
}
