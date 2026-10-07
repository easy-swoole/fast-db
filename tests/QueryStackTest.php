<?php

declare(strict_types=1);

namespace EasySwoole\FastDb\Tests;

use EasySwoole\FastDb\FastDb;
use EasySwoole\FastDb\Mysql\Connection;
use PHPUnit\Framework\TestCase;

final class QueryStackTest extends TestCase
{
    private function database(): FastDb
    {
        $client = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()
            ->onlyMethods(['rawQuery'])->getMock();
        $client->method('rawQuery')->willReturn([]);
        $db = (new FastDb())->isEnableQueryStack(true);
        (new \ReflectionProperty(FastDb::class, 'currentConnection'))
            ->setValue($db, [\Swoole\Coroutine::getCid() => ['default' => $client]]);
        return $db;
    }

    public function testNegativeIndexesCountFromLastQuery(): void
    {
        $db = $this->database();
        $db->rawQuery('SELECT 11');
        $db->rawQuery('SELECT 22');
        $db->rawQuery('SELECT 33');

        $this->assertSame('SELECT 33', $db->getQueryStack(-1)->rawQuery);
        $this->assertSame('SELECT 22', $db->getQueryStack(-2)->rawQuery);
        $this->assertSame('SELECT 11', $db->getQueryStack(-3)->rawQuery);
        $this->assertSame($db->getQueryStack(2), $db->getQueryStack(-1));
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-3));
        $this->assertCount(3, $db->getQueryStack());
        $this->assertNull($db->getQueryStack(-4));
        $this->assertNull($db->getQueryStack(3));
    }

    public function testOnlyQueryIsAvailableAtMinusOne(): void
    {
        $db = $this->database();
        $db->rawQuery('SELECT 1');
        $this->assertSame($db->getQueryStack(0), $db->getQueryStack(-1));
        $this->assertSame('SELECT 1', $db->getQueryStack(-1)->rawQuery);
        $this->assertNull($db->getQueryStack(-2));
    }

    public function testMissingStackReturnsNull(): void
    {
        $db = $this->database();
        $this->assertNull($db->getQueryStack(-1));
        $this->assertNull($db->getQueryStack(0));
        $this->assertNull($db->getQueryStack());
    }
}
