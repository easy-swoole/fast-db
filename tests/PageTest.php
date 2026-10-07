<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\Beans\Page;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    public function testDefaultPageSupportsLimitMode(): void
    {
        $page = new Page();
        $this->assertNull($page->getPage());
        $this->assertSame(10, $page->getPageSize());
        $this->assertSame([10], $page->toLimitArray());
        $this->assertFalse($page->isWithTotalCount());
    }

    public function testExplicitNullSupportsCustomLimit(): void
    {
        $page = new Page(null, true, 20);
        $this->assertNull($page->getPage());
        $this->assertSame([20], $page->toLimitArray());
        $this->assertTrue($page->isWithTotalCount());
    }

    public function testNumberedPagePreservesOffset(): void
    {
        $page = new Page(3, false, 20);
        $this->assertSame(3, $page->getPage());
        $this->assertSame([40, 20], $page->toLimitArray());
    }
}
